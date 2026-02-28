<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\FeedbackComment;
use App\Models\FeedbackItem;
use App\Models\FeedbackVote;
use App\Models\RoadmapItem;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FeedbackController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'sort' => ['nullable', Rule::in(['top', 'newest'])],
            'type' => ['nullable', Rule::in(['all', ...FeedbackItem::TYPES])],
        ]);

        $sort = $validated['sort'] ?? 'top';
        $type = $validated['type'] ?? 'all';

        $query = FeedbackItem::query()
            ->with('user:id,name')
            ->withCount([
                'comments as comment_count' => fn ($builder) => $builder
                    ->where('is_spam', false)
                    ->whereNull('deleted_at'),
            ]);
        if ($this->supportsSystemThreadColumn()) {
            $query->where('is_system_thread', false);
        }

        if ($type !== 'all') {
            $query->where('type', $type);
        }

        if ($sort === 'newest') {
            $query->orderByDesc('created_at');
        } else {
            $query->orderByDesc('vote_count')->orderByDesc('created_at');
        }

        $items = $query->limit(400)->get();

        $identity = $this->resolveVoterIdentity($request);
        $voteLookup = [];
        if ($identity['voter_key'] !== null && $items->isNotEmpty()) {
            $votes = FeedbackVote::query()
                ->where('voter_key', $identity['voter_key'])
                ->whereIn('feedback_item_id', $items->pluck('id'))
                ->get(['feedback_item_id', 'direction']);

            foreach ($votes as $vote) {
                $voteLookup[$vote->feedback_item_id] = (int) ($vote->direction ?: 1);
            }
        }

        $roadmapItems = RoadmapItem::query()
            ->with('feedbackItem:id,title,status')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(300)
            ->get();

        $announcements = Announcement::query()
            ->with('feedbackItem:id,title,status')
            ->where('is_published', true)
            ->where(function ($query) {
                $query->whereNull('published_at')->orWhere('published_at', '<=', now());
            })
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(60)
            ->get();

        $threadIds = $roadmapItems->pluck('feedback_item_id')
            ->filter()
            ->merge($announcements->pluck('feedback_item_id')->filter())
            ->unique()
            ->values();

        $commentCounts = collect();
        if ($threadIds->isNotEmpty()) {
            $commentCounts = FeedbackComment::query()
                ->selectRaw('feedback_item_id, COUNT(*) as aggregate')
                ->whereIn('feedback_item_id', $threadIds->all())
                ->where('is_spam', false)
                ->whereNull('deleted_at')
                ->groupBy('feedback_item_id')
                ->pluck('aggregate', 'feedback_item_id');
        }

        return response()->json([
            'sort' => $sort,
            'type' => $type,
            'items' => $items->map(function (FeedbackItem $item) use ($voteLookup) {
                return $this->formatItem($item, $voteLookup[$item->id] ?? 0);
            })->values(),
            'roadmap_items' => $roadmapItems
                ->map(fn (RoadmapItem $roadmapItem) => $this->formatRoadmapItem(
                    $roadmapItem,
                    (int) ($commentCounts[$roadmapItem->feedback_item_id] ?? 0)
                ))
                ->values(),
            'announcements' => $announcements
                ->map(fn (Announcement $announcement) => $this->formatAnnouncement(
                    $announcement,
                    (int) ($commentCounts[$announcement->feedback_item_id] ?? 0)
                ))
                ->values(),
        ]);
    }

    public function showItem(Request $request, FeedbackItem $feedbackItem)
    {
        if ($this->supportsSystemThreadColumn() && $feedbackItem->is_system_thread) {
            abort(404);
        }

        $feedbackItem->loadMissing('user:id,name');

        $identity = $this->resolveVoterIdentity($request);
        $voteDirection = 0;
        $viewerId = $request->user()?->id;
        $viewerIsAdmin = $this->isAdminUser($request);

        if ($identity['voter_key']) {
            $voteDirection = (int) (FeedbackVote::query()
                ->where('feedback_item_id', $feedbackItem->id)
                ->where('voter_key', $identity['voter_key'])
                ->value('direction') ?: 0);
        }

        $feedbackItem->loadCount([
            'comments as comment_count' => fn ($builder) => $builder
                ->where('is_spam', false)
                ->whereNull('deleted_at'),
        ]);

        $feedbackItem->load([
            'comments' => fn ($builder) => $builder
                ->where('is_spam', false)
                ->whereNull('deleted_at')
                ->with('user:id,name,email')
                ->orderByDesc('created_at')
                ->limit(200),
        ]);

        return response()->json([
            'item' => $this->formatItem($feedbackItem, $voteDirection, true, $viewerId, $viewerIsAdmin),
        ]);
    }

    public function storeIdea(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'message' => 'Please sign in to suggest a feature.',
            ], 401);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'description' => ['required', 'string', 'min:10', 'max:6000'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
        $identity = $this->resolveVoterIdentity($request);
        $initialVoteDirection = 0;

        $item = DB::transaction(function () use ($validated, $request, $identity, $user, &$initialVoteDirection) {
            $itemAttributes = [
                'title' => trim((string) $validated['title']),
                'description' => trim((string) $validated['description']),
                'type' => FeedbackItem::TYPE_IDEA,
                'status' => FeedbackItem::STATUS_SUBMITTED,
                'contact_email' => $validated['email'] ?? null,
                'submitted_ip' => $request->ip(),
                'user_id' => $user->id,
            ];
            if ($this->supportsSystemThreadColumn()) {
                $itemAttributes['is_system_thread'] = false;
            }

            $item = FeedbackItem::query()->create($itemAttributes);

            if ($identity['voter_key'] !== null) {
                FeedbackVote::query()->create([
                    'feedback_item_id' => $item->id,
                    'user_id' => $identity['user_id'],
                    'ip_address' => $identity['ip_address'],
                    'voter_key' => $identity['voter_key'],
                    'direction' => 1,
                ]);

                $item->vote_count = 1;
                $item->save();
                $initialVoteDirection = 1;
            }

            return $item;
        });

        $item->loadMissing('user:id,name');

        return response()->json([
            'message' => 'Thanks. Your suggestion has been received.',
            'item' => $this->formatItem($item, $initialVoteDirection),
        ], 201);
    }

    public function storeBug(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'description' => ['required', 'string', 'min:10', 'max:6000'],
            'email' => ['nullable', 'email', 'max:255'],
            'browser_notes' => ['nullable', 'string', 'max:800'],
            'screenshot' => ['nullable', 'image', 'max:8192'],
        ]);

        $screenshotPath = null;
        if ($request->hasFile('screenshot')) {
            $screenshotPath = $request->file('screenshot')->store('feedback-bugs', 'public');
        }

        $itemAttributes = [
            'title' => trim((string) $validated['title']),
            'description' => trim((string) $validated['description']),
            'type' => FeedbackItem::TYPE_BUG,
            'status' => FeedbackItem::STATUS_REPORTED,
            'contact_email' => $validated['email'] ?? null,
            'browser_notes' => $validated['browser_notes'] ?? null,
            'screenshot_path' => $screenshotPath,
            'submitted_ip' => $request->ip(),
        ];
        if ($this->supportsSystemThreadColumn()) {
            $itemAttributes['is_system_thread'] = false;
        }

        $item = FeedbackItem::query()->create($itemAttributes);

        return response()->json([
            'message' => 'Thanks. Your bug report has been logged.',
            'item' => $this->formatItem($item, 0),
        ], 201);
    }

    public function storeComment(Request $request, FeedbackItem $feedbackItem)
    {
        return $this->storeCommentForFeedbackItem($request, $feedbackItem, 'Comments are locked for this idea.');
    }

    public function destroyComment(Request $request, FeedbackItem $feedbackItem, FeedbackComment $feedbackComment)
    {
        return $this->destroyCommentForFeedbackItem($request, $feedbackItem, $feedbackComment);
    }

    public function showRoadmapItem(Request $request, RoadmapItem $roadmapItem)
    {
        $thread = $this->ensureRoadmapCommentThread($roadmapItem);
        $threadPayload = $this->formatCommentThreadPayload($thread, $request);

        return response()->json([
            'item' => array_merge(
                $this->formatRoadmapItem($roadmapItem, $threadPayload['comment_count']),
                $threadPayload
            ),
        ]);
    }

    public function storeRoadmapComment(Request $request, RoadmapItem $roadmapItem)
    {
        $thread = $this->ensureRoadmapCommentThread($roadmapItem);

        return $this->storeCommentForFeedbackItem($request, $thread, 'Comments are locked for this roadmap item.');
    }

    public function destroyRoadmapComment(Request $request, RoadmapItem $roadmapItem, FeedbackComment $feedbackComment)
    {
        $thread = $this->ensureRoadmapCommentThread($roadmapItem);

        return $this->destroyCommentForFeedbackItem($request, $thread, $feedbackComment);
    }

    public function showAnnouncement(Request $request, Announcement $announcement)
    {
        $isLive = (bool) $announcement->is_published
            && ($announcement->published_at === null || $announcement->published_at->lte(now()));
        if (! $isLive) {
            abort(404);
        }

        $thread = $this->ensureAnnouncementCommentThread($announcement);
        $threadPayload = $this->formatCommentThreadPayload($thread, $request);

        return response()->json([
            'item' => array_merge(
                $this->formatAnnouncement($announcement, $threadPayload['comment_count']),
                $threadPayload
            ),
        ]);
    }

    public function storeAnnouncementComment(Request $request, Announcement $announcement)
    {
        $isLive = (bool) $announcement->is_published
            && ($announcement->published_at === null || $announcement->published_at->lte(now()));
        if (! $isLive) {
            abort(404);
        }

        $thread = $this->ensureAnnouncementCommentThread($announcement);

        return $this->storeCommentForFeedbackItem($request, $thread, 'Comments are locked for this announcement.');
    }

    public function destroyAnnouncementComment(Request $request, Announcement $announcement, FeedbackComment $feedbackComment)
    {
        $isLive = (bool) $announcement->is_published
            && ($announcement->published_at === null || $announcement->published_at->lte(now()));
        if (! $isLive) {
            abort(404);
        }

        $thread = $this->ensureAnnouncementCommentThread($announcement);

        return $this->destroyCommentForFeedbackItem($request, $thread, $feedbackComment);
    }

    public function vote(Request $request, FeedbackItem $feedbackItem)
    {
        if ($this->supportsSystemThreadColumn() && $feedbackItem->is_system_thread) {
            return response()->json([
                'message' => 'Voting is unavailable for this entry.',
            ], 422);
        }

        $validated = $request->validate([
            'direction' => ['nullable', Rule::in(['up', 'down'])],
        ]);

        $direction = $validated['direction'] ?? 'up';

        $identity = $this->resolveVoterIdentity($request);
        if ($identity['voter_key'] === null) {
            return response()->json([
                'message' => 'Unable to record vote right now.',
            ], 422);
        }

        $existing = FeedbackVote::query()
            ->where('feedback_item_id', $feedbackItem->id)
            ->where('voter_key', $identity['voter_key'])
            ->first();

        $delta = 0;
        $userVote = null;

        if ($direction === 'up') {
            if ($existing) {
                $previous = (int) ($existing->direction ?: 1);
                if ($previous === 1) {
                    return response()->json([
                        'status' => 'already_voted',
                        'vote_count' => $feedbackItem->vote_count,
                        'has_voted' => true,
                        'user_vote' => 'up',
                    ]);
                }

                $existing->direction = 1;
                $existing->save();
                $delta = 1 - $previous;
            } else {
                try {
                    FeedbackVote::query()->create([
                        'feedback_item_id' => $feedbackItem->id,
                        'user_id' => $identity['user_id'],
                        'ip_address' => $identity['ip_address'],
                        'voter_key' => $identity['voter_key'],
                        'direction' => 1,
                    ]);
                } catch (QueryException $exception) {
                    $latest = FeedbackVote::query()
                        ->where('feedback_item_id', $feedbackItem->id)
                        ->where('voter_key', $identity['voter_key'])
                        ->first();

                    $latestDirection = (int) ($latest?->direction ?: 1);
                    return response()->json([
                        'status' => 'already_voted',
                        'vote_count' => $feedbackItem->vote_count,
                        'has_voted' => $latestDirection === 1,
                        'user_vote' => $latestDirection === 1 ? 'up' : null,
                    ]);
                }

                $delta = 1;
            }

            $userVote = 'up';
        } else {
            if (! $existing) {
                return response()->json([
                    'status' => 'no_vote',
                    'vote_count' => $feedbackItem->vote_count,
                    'has_voted' => false,
                    'user_vote' => null,
                ]);
            }

            $previous = (int) ($existing->direction ?: 1);
            $existing->delete();
            $delta = -1 * $previous;
            $userVote = null;
        }

        if ($delta !== 0) {
            FeedbackItem::query()
                ->whereKey($feedbackItem->id)
                ->update([
                    'vote_count' => DB::raw('CASE WHEN vote_count + ('.(int) $delta.') < 0 THEN 0 ELSE vote_count + ('.(int) $delta.') END'),
                ]);
            $feedbackItem->refresh();
        }

        return response()->json([
            'status' => 'voted',
            'vote_count' => $feedbackItem->vote_count,
            'has_voted' => $userVote !== null,
            'user_vote' => $userVote,
        ]);
    }

    private function storeCommentForFeedbackItem(Request $request, FeedbackItem $feedbackItem, string $lockedMessage)
    {
        if ($feedbackItem->comments_locked) {
            return response()->json([
                'message' => $lockedMessage,
            ], 423);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:1000'],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $user = $request->user();
        $body = trim((string) $validated['body']);

        $comment = FeedbackComment::query()->create([
            'feedback_item_id' => $feedbackItem->id,
            'user_id' => $user?->id,
            'author_name' => $user?->name ?: trim((string) ($validated['name'] ?? '')) ?: 'Guest',
            'author_email' => $user?->email ?: ($validated['email'] ?? null),
            'body' => $body,
            'is_admin' => false,
            'submitted_ip' => $request->ip(),
        ]);

        $comment->load('user:id,name,email');

        $commentCount = (int) FeedbackComment::query()
            ->where('feedback_item_id', $feedbackItem->id)
            ->where('is_spam', false)
            ->whereNull('deleted_at')
            ->count();

        return response()->json([
            'message' => 'Comment posted.',
            'comment' => $this->formatComment($comment, $user?->id, $this->isAdminUser($request)),
            'comment_count' => $commentCount,
        ], 201);
    }

    private function destroyCommentForFeedbackItem(Request $request, FeedbackItem $feedbackItem, FeedbackComment $feedbackComment)
    {
        if ((int) $feedbackComment->feedback_item_id !== (int) $feedbackItem->id) {
            abort(404);
        }

        $user = $request->user();
        if (! $user) {
            return response()->json([
                'message' => 'Please sign in to manage comments.',
            ], 401);
        }

        $isAdmin = $this->isAdminUser($request);
        $canDeleteOwn = $feedbackComment->user_id !== null
            && (int) $feedbackComment->user_id === (int) $user->id;

        if (! $isAdmin && ! $canDeleteOwn) {
            return response()->json([
                'message' => 'You can only remove your own comments.',
            ], 403);
        }

        $feedbackComment->delete();

        $commentCount = (int) FeedbackComment::query()
            ->where('feedback_item_id', $feedbackItem->id)
            ->where('is_spam', false)
            ->whereNull('deleted_at')
            ->count();

        return response()->json([
            'message' => 'Comment deleted.',
            'comment_id' => $feedbackComment->id,
            'comment_count' => $commentCount,
        ]);
    }

    private function ensureRoadmapCommentThread(RoadmapItem $roadmapItem): FeedbackItem
    {
        if ($roadmapItem->feedback_item_id) {
            $thread = FeedbackItem::query()->find($roadmapItem->feedback_item_id);
            if ($thread) {
                return $thread;
            }
        }

        $threadAttributes = [
            'title' => 'Roadmap: '.$roadmapItem->title,
            'description' => $roadmapItem->description ?: 'Discussion thread for this roadmap item.',
            'type' => FeedbackItem::TYPE_IMPROVEMENT,
            'status' => FeedbackItem::STATUS_CLOSED,
            'comments_locked' => false,
            'vote_count' => 0,
        ];
        if ($this->supportsSystemThreadColumn()) {
            $threadAttributes['is_system_thread'] = true;
        }

        $thread = FeedbackItem::query()->create($threadAttributes);

        $roadmapItem->feedback_item_id = $thread->id;
        $roadmapItem->save();

        return $thread;
    }

    private function ensureAnnouncementCommentThread(Announcement $announcement): FeedbackItem
    {
        if ($announcement->feedback_item_id) {
            $thread = FeedbackItem::query()->find($announcement->feedback_item_id);
            if ($thread) {
                return $thread;
            }
        }

        $threadAttributes = [
            'title' => 'Announcement: '.$announcement->title,
            'description' => $announcement->body ?: 'Discussion thread for this announcement.',
            'type' => FeedbackItem::TYPE_IMPROVEMENT,
            'status' => FeedbackItem::STATUS_CLOSED,
            'comments_locked' => false,
            'vote_count' => 0,
        ];
        if ($this->supportsSystemThreadColumn()) {
            $threadAttributes['is_system_thread'] = true;
        }

        $thread = FeedbackItem::query()->create($threadAttributes);

        $announcement->feedback_item_id = $thread->id;
        $announcement->save();

        return $thread;
    }

    private function formatCommentThreadPayload(FeedbackItem $thread, Request $request): array
    {
        $viewerId = $request->user()?->id;
        $viewerIsAdmin = $this->isAdminUser($request);

        $thread->loadCount([
            'comments as comment_count' => fn ($builder) => $builder
                ->where('is_spam', false)
                ->whereNull('deleted_at'),
        ]);

        $thread->load([
            'comments' => fn ($builder) => $builder
                ->where('is_spam', false)
                ->whereNull('deleted_at')
                ->with('user:id,name,email')
                ->orderByDesc('created_at')
                ->limit(200),
        ]);

        return [
            'feedback_item_id' => $thread->id,
            'comment_count' => (int) ($thread->comment_count ?? 0),
            'comments_locked' => (bool) $thread->comments_locked,
            'comments' => $thread->comments
                ->map(fn (FeedbackComment $comment) => $this->formatComment($comment, $viewerId, $viewerIsAdmin))
                ->values()
                ->all(),
        ];
    }

    private function resolveVoterIdentity(Request $request): array
    {
        $userId = $request->user()?->id;
        if ($userId) {
            return [
                'user_id' => $userId,
                'ip_address' => $request->ip(),
                'voter_key' => 'user:'.$userId,
            ];
        }

        $ip = $request->ip();
        if (! $ip) {
            return [
                'user_id' => null,
                'ip_address' => null,
                'voter_key' => null,
            ];
        }

        return [
            'user_id' => null,
            'ip_address' => $ip,
            'voter_key' => 'ip:'.$ip,
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

    private function formatItem(
        FeedbackItem $item,
        int $userVoteDirection,
        bool $withComments = false,
        ?int $viewerId = null,
        bool $viewerIsAdmin = false
    ): array
    {
        $userVote = $userVoteDirection === 1 ? 'up' : null;
        $normalizedDescription = $this->normalizeFeedbackDescription($item->description);

        $payload = [
            'id' => $item->id,
            'title' => $item->title,
            'description' => $normalizedDescription,
            'description_preview' => Str::limit($normalizedDescription, 200),
            'type' => $item->type,
            'status' => $item->status,
            'vote_count' => $item->vote_count,
            'comment_count' => (int) ($item->comment_count ?? 0),
            'comments_locked' => (bool) $item->comments_locked,
            'has_voted' => $userVote !== null,
            'user_vote' => $userVote,
            'browser_notes' => $item->browser_notes,
            'screenshot_url' => $item->screenshot_path ? asset('storage/'.$item->screenshot_path) : null,
            'author_name' => $item->user?->name ?: 'Penny Community',
            'created_at' => optional($item->created_at)->toIso8601String(),
            'updated_at' => optional($item->updated_at)->toIso8601String(),
        ];

        if ($withComments) {
            $payload['comments'] = $item->comments
                ->map(fn (FeedbackComment $comment) => $this->formatComment($comment, $viewerId, $viewerIsAdmin))
                ->values()
                ->all();
        }

        return $payload;
    }

    private function normalizeFeedbackDescription(?string $description): string
    {
        $value = trim((string) $description);
        if ($value === '') {
            return '';
        }

        return (string) preg_replace('/^\[Topics:\s*[^\]]+\]\s*/i', '', $value);
    }

    private function formatRoadmapItem(RoadmapItem $roadmapItem, int $commentCount = 0): array
    {
        return [
            'id' => $roadmapItem->id,
            'title' => $roadmapItem->title,
            'description' => $roadmapItem->description,
            'status' => $roadmapItem->status,
            'sort_order' => (int) $roadmapItem->sort_order,
            'feedback_item_id' => $roadmapItem->feedback_item_id,
            'feedback_item_title' => $roadmapItem->feedbackItem?->title,
            'comment_count' => $commentCount,
            'created_at' => optional($roadmapItem->created_at)->toIso8601String(),
            'updated_at' => optional($roadmapItem->updated_at)->toIso8601String(),
        ];
    }

    private function formatAnnouncement(Announcement $announcement, int $commentCount = 0): array
    {
        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'body' => $announcement->body,
            'tags' => $announcement->tags ?? [],
            'feedback_item_id' => $announcement->feedback_item_id,
            'feedback_item_title' => $announcement->feedbackItem?->title,
            'feedback_item_status' => $announcement->feedbackItem?->status,
            'comment_count' => $commentCount,
            'published_at' => optional($announcement->published_at)->toIso8601String(),
            'created_at' => optional($announcement->created_at)->toIso8601String(),
        ];
    }

    private function formatComment(FeedbackComment $comment, ?int $viewerId = null, bool $viewerIsAdmin = false): array
    {
        $commentUserId = $comment->user_id !== null ? (int) $comment->user_id : null;
        $canDelete = $viewerIsAdmin
            || ($viewerId !== null && $commentUserId !== null && $commentUserId === (int) $viewerId);

        return [
            'id' => $comment->id,
            'author' => $comment->author_name ?: ($comment->user?->name ?: 'Guest'),
            'body' => $comment->body,
            'is_admin' => (bool) $comment->is_admin,
            'can_delete' => $canDelete,
            'created_at' => optional($comment->created_at)->toIso8601String(),
        ];
    }

    private function isAdminUser(Request $request): bool
    {
        return (string) ($request->user()?->role ?? '') === 'admin';
    }
}
