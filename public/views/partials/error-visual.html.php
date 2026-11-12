<div class="error-visual" aria-hidden="true">
    <span class="error-orbit error-orbit-outer"></span>
    <span class="error-orbit error-orbit-inner"></span>
    <span class="error-spark error-spark-start">✦</span>
    <span class="error-spark error-spark-end">✧</span>
    <div class="error-emblem">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false">
            <?php if ($errorVisualKind === 'signal'): ?>
                <path d="M2 12h4l3-8 6 16 3-8h4"/>
                <path d="M6 3 3 6m15 15 3-3"/>
            <?php elseif ($errorVisualKind === 'shield'): ?>
                <path d="m12 3 8 3v6c0 4-8 9-8 9s-8-5-8-9V6Z"/>
                <path d="M12 8v5m0 3h.01"/>
            <?php else: ?>
                <circle cx="12" cy="12" r="9"/>
                <path d="m16 8-2.5 5.5L8 16l2.5-5.5Z"/>
            <?php endif; ?>
        </svg>
    </div>
    <span class="error-code"><?= $escape($errorVisualCode) ?></span>
    <span class="error-visual-label">AniVibe</span>
</div>
