<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\FeedbackComment;
use App\Models\FeedbackItem;
use App\Models\RoadmapItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminFeedbackController extends Controller
{
    public function createItem(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'description' => ['required', 'string', 'min:3', 'max:6000'],
            'status' => ['nullable', Rule::in(FeedbackItem::STATUSES)],
            'type' => ['nullable', Rule::in(FeedbackItem::TYPES)],
        ]);

        $itemAttributes = [
            'title' => trim((string) $validated['title']),
            'description' => trim((string) $validated['description']),
            'type' => $validated['type'] ?? FeedbackItem::TYPE_IDEA,
            'status' => $validated['status'] ?? FeedbackItem::STATUS_SUBMITTED,
            'vote_count' => 0,
        ];
        if ($this->supportsSystemThreadColumn()) {
            $itemAttributes['is_system_thread'] = false;
        }

        $item = FeedbackItem::query()->create($itemAttributes);

        $item->loadCount([
            'comments as comment_count' => fn ($query) => $query
                ->where('is_spam', false)
                ->whereNull('deleted_at'),
        ]);

        return response()->json([
            'item' => $this->formatItem($item),
        ], 201);
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', ...FeedbackItem::STATUSES])],
            'type' => ['nullable', Rule::in(['all', ...FeedbackItem::TYPES])],
            'comment_feedback_item_id' => ['nullable', 'integer', 'exists:feedback_items,id'],
            'include_deleted_comments' => ['nullable', 'boolean'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $status = $validated['status'] ?? 'all';
        $type = $validated['type'] ?? 'all';
        $commentFeedbackItemId = $validated['comment_feedback_item_id'] ?? null;
        $includeDeletedComments = (bool) ($validated['include_deleted_comments'] ?? true);

        $itemsQuery = FeedbackItem::query()
            ->withCount([
                'comments as comment_count' => fn ($query) => $query
                    ->where('is_spam', false)
                    ->whereNull('deleted_at'),
            ])
            ->orderByDesc('created_at');
        if ($this->supportsSystemThreadColumn()) {
            $itemsQuery->where('is_system_thread', false);
        }

        if ($search !== '') {
            $itemsQuery->where(function ($query) use ($search) {
                $query
                    ->where('title', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        if ($status !== 'all') {
            $itemsQuery->where('status', $status);
        }

        if ($type !== 'all') {
            $itemsQuery->where('type', $type);
        }

        $items = $itemsQuery
            ->limit(500)
            ->get()
            ->map(fn (FeedbackItem $item) => $this->formatItem($item))
            ->values();

        $roadmapItems = RoadmapItem::query()
            ->with('feedbackItem:id,title,status')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->map(fn (RoadmapItem $roadmapItem) => $this->formatRoadmapItem($roadmapItem))
            ->values();

        $announcements = Announcement::query()
            ->with('feedbackItem:id,title,status')
            ->orderByDesc('created_at')
            ->limit(250)
            ->get()
            ->map(fn (Announcement $announcement) => $this->formatAnnouncement($announcement))
            ->values();

        $commentsQuery = FeedbackComment::query()->with([
            'feedbackItem:id,title',
            'user:id,name,email',
        ]);

        if ($includeDeletedComments) {
            $commentsQuery->withTrashed();
        }

        if ($commentFeedbackItemId) {
            $commentsQuery->where('feedback_item_id', $commentFeedbackItemId);
        }

        $comments = $commentsQuery
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->map(fn (FeedbackComment $comment) => $this->formatComment($comment))
            ->values();

        return response()->json([
            'items' => $items,
            'roadmap_items' => $roadmapItems,
            'announcements' => $announcements,
            'comments' => $comments,
            'meta' => [
                'statuses' => FeedbackItem::STATUSES,
                'types' => FeedbackItem::TYPES,
                'roadmap_statuses' => RoadmapItem::STATUSES,
            ],
        ]);
    }

    public function showItem(FeedbackItem $feedbackItem)
    {
        $feedbackItem->loadCount([
            'comments as comment_count' => fn ($query) => $query
                ->where('is_spam', false)
                ->whereNull('deleted_at'),
        ]);

        $feedbackItem->load([
            'comments' => fn ($query) => $query
                ->withTrashed()
                ->with('user:id,name,email')
                ->orderByDesc('created_at')
                ->limit(300),
            'roadmapItems:id,feedback_item_id,status,sort_order,title,description,created_at,updated_at',
        ]);

        return response()->json([
            'item' => $this->formatItem($feedbackItem, true),
        ]);
    }

    public function updateItem(Request $request, FeedbackItem $feedbackItem)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'description' => ['required', 'string', 'min:3', 'max:6000'],
            'type' => ['required', Rule::in(FeedbackItem::TYPES)],
            'status' => ['required', Rule::in(FeedbackItem::STATUSES)],
            'comments_locked' => ['nullable', 'boolean'],
            'admin_response' => ['nullable', 'string', 'max:6000'],
        ]);

        $feedbackItem->title = trim((string) $validated['title']);
        $feedbackItem->description = trim((string) $validated['description']);
        $feedbackItem->type = $validated['type'];
        $feedbackItem->status = $validated['status'];

        if (array_key_exists('comments_locked', $validated)) {
            $feedbackItem->comments_locked = (bool) $validated['comments_locked'];
        }

        if (array_key_exists('admin_response', $validated)) {
            $response = trim((string) ($validated['admin_response'] ?? ''));
            $feedbackItem->admin_response = $response === '' ? null : $response;
        }

        $feedbackItem->save();

        if (in_array($feedbackItem->status, RoadmapItem::STATUSES, true)) {
            RoadmapItem::query()
                ->where('feedback_item_id', $feedbackItem->id)
                ->update(['status' => $feedbackItem->status]);
        }

        $feedbackItem->loadCount([
            'comments as comment_count' => fn ($query) => $query
                ->where('is_spam', false)
                ->whereNull('deleted_at'),
        ]);

        return response()->json([
            'item' => $this->formatItem($feedbackItem),
        ]);
    }

    public function destroyItem(FeedbackItem $feedbackItem)
    {
        $feedbackItem->delete();

        return response()->json([
            'message' => 'Idea deleted.',
        ]);
    }

    public function promoteToRoadmap(Request $request, FeedbackItem $feedbackItem)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(RoadmapItem::STATUSES)],
        ]);

        $existing = RoadmapItem::query()
            ->where('feedback_item_id', $feedbackItem->id)
            ->orderBy('id')
            ->first();

        if ($existing) {
            return response()->json([
                'roadmap_item' => $this->formatRoadmapItem($existing),
                'message' => 'Idea is already on the roadmap.',
            ]);
        }

        $status = $validated['status'] ?? RoadmapItem::STATUS_PLANNED;

        $roadmapItem = RoadmapItem::query()->create([
            'feedback_item_id' => $feedbackItem->id,
            'title' => $feedbackItem->title,
            'description' => $feedbackItem->description,
            'status' => $status,
            'sort_order' => ((int) RoadmapItem::query()->max('sort_order')) + 1,
        ]);

        if ($feedbackItem->status !== $status) {
            $feedbackItem->status = $status;
            $feedbackItem->save();
        }

        $roadmapItem->load('feedbackItem:id,title,status');

        return response()->json([
            'roadmap_item' => $this->formatRoadmapItem($roadmapItem),
        ], 201);
    }

    public function postAdminResponse(Request $request, FeedbackItem $feedbackItem)
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:2000'],
        ]);

        $body = trim((string) $validated['body']);

        $comment = FeedbackComment::query()->create([
            'feedback_item_id' => $feedbackItem->id,
            'user_id' => $request->user()?->id,
            'author_name' => $request->user()?->name ?: 'Penny Team',
            'author_email' => $request->user()?->email,
            'body' => $body,
            'is_admin' => true,
            'submitted_ip' => $request->ip(),
        ]);

        $feedbackItem->admin_response = $body;
        $feedbackItem->save();

        $comment->load(['feedbackItem:id,title', 'user:id,name,email']);

        return response()->json([
            'comment' => $this->formatComment($comment),
            'item' => $this->formatItem($feedbackItem),
        ], 201);
    }

    public function createRoadmapItem(Request $request)
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'min:3', 'max:160'],
            'description' => ['nullable', 'string', 'max:6000'],
            'feedback_item_id' => ['nullable', 'integer', 'exists:feedback_items,id'],
            'status' => ['required', Rule::in(RoadmapItem::STATUSES)],
        ]);

        $feedbackItem = null;
        if (!empty($validated['feedback_item_id'])) {
            $feedbackItem = FeedbackItem::query()->findOrFail((int) $validated['feedback_item_id']);
        }

        $title = trim((string) ($validated['title'] ?? ''));
        if ($title === '' && $feedbackItem) {
            $title = $feedbackItem->title;
        }

        if ($title === '') {
            return response()->json([
                'message' => 'A roadmap title is required.',
            ], 422);
        }

        $description = trim((string) ($validated['description'] ?? ''));
        if ($description === '' && $feedbackItem) {
            $description = $feedbackItem->description;
        }

        $roadmapItem = RoadmapItem::query()->create([
            'feedback_item_id' => $feedbackItem?->id,
            'title' => $title,
            'description' => $description === '' ? null : $description,
            'status' => $validated['status'],
            'sort_order' => ((int) RoadmapItem::query()->max('sort_order')) + 1,
        ]);

        $this->syncLinkedFeedbackStatus($roadmapItem);

        $roadmapItem->load('feedbackItem:id,title,status');

        return response()->json([
            'roadmap_item' => $this->formatRoadmapItem($roadmapItem),
        ], 201);
    }

    public function updateRoadmapItem(Request $request, RoadmapItem $roadmapItem)
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:3', 'max:160'],
            'description' => ['nullable', 'string', 'max:6000'],
            'feedback_item_id' => ['nullable', 'integer', 'exists:feedback_items,id'],
            'status' => ['sometimes', 'required', Rule::in(RoadmapItem::STATUSES)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (array_key_exists('title', $validated)) {
            $roadmapItem->title = trim((string) $validated['title']);
        }

        if (array_key_exists('description', $validated)) {
            $description = trim((string) ($validated['description'] ?? ''));
            $roadmapItem->description = $description === '' ? null : $description;
        }

        if (array_key_exists('feedback_item_id', $validated)) {
            $roadmapItem->feedback_item_id = $validated['feedback_item_id'] ?: null;
        }

        if (array_key_exists('status', $validated)) {
            $roadmapItem->status = $validated['status'];
        }

        if (array_key_exists('sort_order', $validated)) {
            $roadmapItem->sort_order = (int) $validated['sort_order'];
        }

        $roadmapItem->save();

        $this->syncLinkedFeedbackStatus($roadmapItem);

        $roadmapItem->load('feedbackItem:id,title,status');

        return response()->json([
            'roadmap_item' => $this->formatRoadmapItem($roadmapItem),
        ]);
    }

    public function reorderRoadmapItems(Request $request)
    {
        $validated = $request->validate([
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer', 'distinct', 'exists:roadmap_items,id'],
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['ordered_ids'] as $index => $id) {
                RoadmapItem::query()->whereKey($id)->update(['sort_order' => $index + 1]);
            }
        });

        $roadmapItems = RoadmapItem::query()
            ->with('feedbackItem:id,title,status')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (RoadmapItem $roadmapItem) => $this->formatRoadmapItem($roadmapItem))
            ->values();

        return response()->json([
            'roadmap_items' => $roadmapItems,
        ]);
    }

    public function destroyRoadmapItem(RoadmapItem $roadmapItem)
    {
        $roadmapItem->delete();

        return response()->json([
            'message' => 'Roadmap item deleted.',
        ]);
    }

    public function createAnnouncement(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'body' => ['required', 'string', 'min:10', 'max:6000'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:40'],
            'feedback_item_id' => ['nullable', 'integer', 'exists:feedback_items,id'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $feedbackItemId = $validated['feedback_item_id'] ?? null;
        if ($feedbackItemId) {
            $item = FeedbackItem::query()->findOrFail($feedbackItemId);
            if ($item->status !== FeedbackItem::STATUS_SHIPPED) {
                return response()->json([
                    'message' => 'Announcements can only be linked to shipped items.',
                ], 422);
            }
        }

        $isPublished = (bool) ($validated['is_published'] ?? true);
        $tags = $this->normalizeTags($validated['tags'] ?? []);

        $announcement = Announcement::query()->create([
            'title' => trim((string) $validated['title']),
            'body' => trim((string) $validated['body']),
            'tags' => $tags,
            'feedback_item_id' => $feedbackItemId,
            'is_published' => $isPublished,
            'published_at' => $isPublished ? now() : null,
        ]);
        $announcement->load('feedbackItem:id,title,status');

        return response()->json([
            'announcement' => $this->formatAnnouncement($announcement),
        ], 201);
    }

    public function updateAnnouncement(Request $request, Announcement $announcement)
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:3', 'max:180'],
            'body' => ['sometimes', 'required', 'string', 'min:10', 'max:6000'],
            'tags' => ['sometimes', 'array', 'max:10'],
            'tags.*' => ['string', 'max:40'],
            'feedback_item_id' => ['nullable', 'integer', 'exists:feedback_items,id'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('feedback_item_id', $validated) && $validated['feedback_item_id']) {
            $item = FeedbackItem::query()->findOrFail($validated['feedback_item_id']);
            if ($item->status !== FeedbackItem::STATUS_SHIPPED) {
                return response()->json([
                    'message' => 'Announcements can only be linked to shipped items.',
                ], 422);
            }
        }

        if (array_key_exists('title', $validated)) {
            $announcement->title = trim((string) $validated['title']);
        }
        if (array_key_exists('body', $validated)) {
            $announcement->body = trim((string) $validated['body']);
        }
        if (array_key_exists('feedback_item_id', $validated)) {
            $announcement->feedback_item_id = $validated['feedback_item_id'] ?: null;
        }
        if (array_key_exists('tags', $validated)) {
            $announcement->tags = $this->normalizeTags($validated['tags'] ?? []);
        }
        if (array_key_exists('is_published', $validated)) {
            $announcement->is_published = (bool) $validated['is_published'];
            $announcement->published_at = $announcement->is_published
                ? ($announcement->published_at ?? now())
                : null;
        }

        $announcement->save();
        $announcement->load('feedbackItem:id,title,status');

        return response()->json([
            'announcement' => $this->formatAnnouncement($announcement),
        ]);
    }

    public function destroyAnnouncement(Announcement $announcement)
    {
        $announcement->delete();

        return response()->json([
            'message' => 'Announcement deleted.',
        ]);
    }

    public function commentsIndex(Request $request)
    {
        $validated = $request->validate([
            'feedback_item_id' => ['nullable', 'integer', 'exists:feedback_items,id'],
            'include_deleted' => ['nullable', 'boolean'],
        ]);

        $includeDeleted = (bool) ($validated['include_deleted'] ?? true);
        $feedbackItemId = $validated['feedback_item_id'] ?? null;

        $query = FeedbackComment::query()->with([
            'feedbackItem:id,title',
            'user:id,name,email',
        ]);

        if ($includeDeleted) {
            $query->withTrashed();
        }

        if ($feedbackItemId) {
            $query->where('feedback_item_id', $feedbackItemId);
        }

        $comments = $query
            ->orderByDesc('created_at')
            ->limit(600)
            ->get()
            ->map(fn (FeedbackComment $comment) => $this->formatComment($comment))
            ->values();

        return response()->json([
            'comments' => $comments,
        ]);
    }

    public function updateComment(Request $request, string $comment)
    {
        $feedbackComment = FeedbackComment::query()->withTrashed()->findOrFail($comment);

        $validated = $request->validate([
            'is_spam' => ['nullable', 'boolean'],
            'soft_delete' => ['nullable', 'boolean'],
            'restore' => ['nullable', 'boolean'],
            'body' => ['nullable', 'string', 'max:1000'],
        ]);

        if (array_key_exists('is_spam', $validated)) {
            $feedbackComment->is_spam = (bool) $validated['is_spam'];
        }

        if (array_key_exists('body', $validated)) {
            $body = trim((string) ($validated['body'] ?? ''));
            if ($body !== '') {
                $feedbackComment->body = $body;
            }
        }

        $needsSave = array_key_exists('is_spam', $validated) || array_key_exists('body', $validated);

        if ((bool) ($validated['restore'] ?? false) && $feedbackComment->trashed()) {
            $feedbackComment->restore();
        }

        if ($needsSave) {
            $feedbackComment->save();
        }

        if ((bool) ($validated['soft_delete'] ?? false) && ! $feedbackComment->trashed()) {
            $feedbackComment->delete();
        }

        $feedbackComment = FeedbackComment::query()
            ->withTrashed()
            ->with(['feedbackItem:id,title', 'user:id,name,email'])
            ->findOrFail($feedbackComment->id);

        return response()->json([
            'comment' => $this->formatComment($feedbackComment),
        ]);
    }

    public function destroyComment(string $comment)
    {
        $feedbackComment = FeedbackComment::query()->withTrashed()->findOrFail($comment);
        $feedbackComment->forceDelete();

        return response()->json([
            'message' => 'Comment deleted permanently.',
        ]);
    }

    private function syncLinkedFeedbackStatus(RoadmapItem $roadmapItem): void
    {
        if (! $roadmapItem->feedback_item_id) {
            return;
        }

        FeedbackItem::query()
            ->whereKey($roadmapItem->feedback_item_id)
            ->update(['status' => $roadmapItem->status]);
    }

    private function normalizeTags(array $tags): array
    {
        return collect($tags)
            ->map(fn ($tag) => trim((string) $tag))
            ->filter()
            ->unique()
            ->take(10)
            ->values()
            ->all();
    }

    private function formatItem(FeedbackItem $item, bool $withComments = false): array
    {
        $payload = [
            'id' => $item->id,
            'title' => $item->title,
            'description' => $item->description,
            'description_preview' => Str::limit($item->description, 200),
            'type' => $item->type,
            'status' => $item->status,
            'vote_count' => $item->vote_count,
            'comment_count' => (int) ($item->comment_count ?? 0),
            'comments_locked' => (bool) $item->comments_locked,
            'admin_response' => $item->admin_response,
            'contact_email' => $item->contact_email,
            'browser_notes' => $item->browser_notes,
            'screenshot_url' => $item->screenshot_path ? asset('storage/'.$item->screenshot_path) : null,
            'created_at' => optional($item->created_at)->toIso8601String(),
            'updated_at' => optional($item->updated_at)->toIso8601String(),
        ];

        if ($withComments) {
            $payload['comments'] = $item->comments
                ->map(fn (FeedbackComment $comment) => $this->formatComment($comment))
                ->values()
                ->all();
            $payload['roadmap_items'] = $item->roadmapItems
                ->map(fn (RoadmapItem $roadmapItem) => $this->formatRoadmapItem($roadmapItem))
                ->values()
                ->all();
        }

        return $payload;
    }

    private function formatRoadmapItem(RoadmapItem $roadmapItem): array
    {
        return [
            'id' => $roadmapItem->id,
            'title' => $roadmapItem->title,
            'description' => $roadmapItem->description,
            'status' => $roadmapItem->status,
            'sort_order' => (int) $roadmapItem->sort_order,
            'feedback_item_id' => $roadmapItem->feedback_item_id,
            'feedback_item_title' => $roadmapItem->feedbackItem?->title,
            'feedback_item_status' => $roadmapItem->feedbackItem?->status,
            'created_at' => optional($roadmapItem->created_at)->toIso8601String(),
            'updated_at' => optional($roadmapItem->updated_at)->toIso8601String(),
        ];
    }

    private function formatAnnouncement(Announcement $announcement): array
    {
        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'body' => $announcement->body,
            'tags' => $announcement->tags ?? [],
            'feedback_item_id' => $announcement->feedback_item_id,
            'feedback_item_title' => $announcement->feedbackItem?->title,
            'feedback_item_status' => $announcement->feedbackItem?->status,
            'is_published' => (bool) $announcement->is_published,
            'published_at' => optional($announcement->published_at)->toIso8601String(),
            'created_at' => optional($announcement->created_at)->toIso8601String(),
            'updated_at' => optional($announcement->updated_at)->toIso8601String(),
        ];
    }

    private function formatComment(FeedbackComment $comment): array
    {
        return [
            'id' => $comment->id,
            'feedback_item_id' => $comment->feedback_item_id,
            'feedback_item_title' => $comment->feedbackItem?->title,
            'user_id' => $comment->user_id,
            'author' => $comment->author_name ?: ($comment->user?->name ?: 'Guest'),
            'author_email' => $comment->author_email ?: $comment->user?->email,
            'body' => $comment->body,
            'is_admin' => (bool) $comment->is_admin,
            'is_spam' => (bool) $comment->is_spam,
            'is_deleted' => $comment->trashed(),
            'deleted_at' => optional($comment->deleted_at)->toIso8601String(),
            'created_at' => optional($comment->created_at)->toIso8601String(),
            'updated_at' => optional($comment->updated_at)->toIso8601String(),
        ];
    }

    private function supportsSystemThreadColumn(): bool
    {
        try {
            return Schema::hasColumn('feedback_items', 'is_system_thread');
        } catch (\Throwable) {
            return false;
        }
    }
}
