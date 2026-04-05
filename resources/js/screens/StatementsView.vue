<template>
    <section class="screen">
        <div class="screen-header">
            <div>
                <p class="eyebrow">Bank statements</p>
                <h1 class="screen-title">bank statmants unavailible</h1>
            </div>
            <div class="accent-chip">Next update</div>
        </div>

        <div class="card">
            <p class="card-sub">bank statmants unavailible will be availible in next update</p>
            <router-link class="ghost-button" :to="{ name: 'home' }">
                Back home
            </router-link>
        </div>

        <div class="card">
            <div class="card-title">In the meantime</div>
            <p class="card-sub">
                You can still add transactions manually, scan receipts, generate insights, and use chat.
            </p>
        </div>
    </section>
</template>
