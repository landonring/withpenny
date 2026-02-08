<template>
    <section class="screen">
        <div class="screen-header">
            <div>
                <p class="eyebrow">Talk with Penny</p>
                <h1 class="screen-title">Ask anything about your money</h1>
            </div>
            <div class="accent-chip">Chat</div>
        </div>

        <div class="card chat-card">
            <div class="chat-bubble assistant">
                <p>Hi. I am Penny, your calm companion.</p>
                <p>When you are ready, we can explore your money together.</p>
            </div>
            <div class="chat-bubble user">
                <p>This is where your messages will appear.</p>
            </div>
        </div>

        <div class="chat-input">
            <input type="text" placeholder="Chat will open in a later phase" disabled />
            <button type="button" disabled>Send</button>
        </div>
    </section>
</template>
