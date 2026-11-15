<?php
$achievementIconPaths = [
    'spark' => '<path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5Z"/>',
    'star' => '<path d="m12 3 2.8 5.7 6.3.9-4.6 4.5 1.1 6.3-5.6-3-5.6 3 1.1-6.3L3 9.6l6.2-.9Z"/>',
    'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
    'flame' => '<path d="M13 3c1 5-2 5-2 8-2-1-2-3-2-4-3 3-4 5-4 8a7 7 0 0 0 14 0c0-5-3-7-6-12Z"/><path d="M12 14c-3 3-1 6 1 6 3-1 2-4-1-6Z"/>',
    'crown' => '<path d="m3 6 4.5 5L12 4l4.5 7L21 6l-2 13H5Z"/><path d="M6 16h12"/>',
    'play' => '<rect x="3" y="4" width="18" height="16" rx="3"/><path d="m10 8 6 4-6 4Z"/>',
    'ribbon' => '<circle cx="12" cy="8" r="5"/><path d="m8 12-2 9 6-3 6 3-2-9"/>',
    'trophy' => '<path d="M8 3h8v5a4 4 0 0 1-8 0ZM8 5H4v2a4 4 0 0 0 5 4M16 5h4v2a4 4 0 0 1-5 4M12 12v6M8 21h8M9 18h6"/>',
    'compass' => '<circle cx="12" cy="12" r="9"/><path d="m16 8-2.5 5.5L8 16l2.5-5.5Z"/>',
    'screen' => '<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4M8 9h8M8 12h5"/>',
];
?>
<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><?= $achievementIconPaths[$achievement['icon']] ?? $achievementIconPaths['star'] ?></svg>
