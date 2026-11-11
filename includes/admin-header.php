<?php
/**
 * Admin panel layout + shared UI helpers.
 *
 *   require __DIR__ . '/../includes/admin-header.php';
 *   $me = admin_start('Courses', 'courses.view', 'courses');   // auth + permission + <head>, sidebar, top bar
 *   ... page body ...
 *   admin_end();
 *
 * All POST handlers must call require_csrf() (done automatically by admin_post()).
 */
declare(strict_types=1);

require_once __DIR__ . '/../api/config.php';
require_once APP_ROOT . '/includes/admin-sidebar.php';

header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');

// ------------------------------------------------------------------
// Icons (inline SVG paths, 24x24). Outline icons use the stroke; brand glyphs
// (official marks, same paths as js/components.jsx FILLED) are rendered filled.
// ------------------------------------------------------------------
function icon(string $name, string $class = 'i'): string
{
    static $brand = [
        'whatsapp' => '<path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>',
        'youtube' => '<path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>',
        'facebook' => '<path d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z"/>',
        'linkedin' => '<path d="M4.98 3.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zM3 9.5h4V21H3zM9.5 9.5h3.8v1.6h.1c.5-1 1.8-2 3.8-2 4 0 4.8 2.6 4.8 6V21h-4v-5.1c0-1.2 0-2.8-1.7-2.8s-2 1.3-2 2.7V21h-4z"/>',
        'telegram' => '<path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/>',
        'instagram' => '<path d="M7.0301.084c-1.2768.0602-2.1487.264-2.911.5634-.7888.3075-1.4575.72-2.1228 1.3877-.6652.6677-1.075 1.3368-1.3802 2.127-.2954.7638-.4956 1.6365-.552 2.914-.0564 1.2775-.0689 1.6882-.0626 4.947.0062 3.2586.0206 3.6671.0825 4.9473.061 1.2765.264 2.1482.5635 2.9107.308.7889.72 1.4573 1.388 2.1228.6679.6655 1.3365 1.0743 2.1285 1.38.7632.295 1.6361.4961 2.9134.552 1.2773.056 1.6884.069 4.9462.0627 3.2578-.0062 3.668-.0207 4.9478-.0814 1.28-.0607 2.147-.2652 2.9098-.5633.7889-.3086 1.4578-.72 2.1228-1.3881.665-.6682 1.0745-1.3378 1.3795-2.1284.2957-.7632.4966-1.636.552-2.9124.056-1.2809.0692-1.6898.063-4.948-.0063-3.2583-.021-3.6668-.0817-4.9465-.0607-1.2797-.264-2.1487-.5633-2.9117-.3084-.7889-.72-1.4568-1.3876-2.1228C21.2982 1.33 20.628.9208 19.8378.6165 19.074.321 18.2017.1197 16.9244.0645 15.6471.0093 15.236-.005 11.977.0014 8.718.0076 8.31.0215 7.0301.0839m.1402 21.6932c-1.17-.0509-1.8053-.2453-2.2287-.408-.5606-.216-.96-.4771-1.3819-.895-.422-.4178-.6811-.8186-.9-1.378-.1644-.4234-.3624-1.058-.4171-2.228-.0595-1.2645-.072-1.6442-.079-4.848-.007-3.2037.0053-3.583.0607-4.848.05-1.169.2456-1.805.408-2.2282.216-.5613.4762-.96.895-1.3816.4188-.4217.8184-.6814 1.3783-.9003.423-.1651 1.0575-.3614 2.227-.4171 1.2655-.06 1.6447-.072 4.848-.079 3.2033-.007 3.5835.005 4.8495.0608 1.169.0508 1.8053.2445 2.228.408.5608.216.96.4754 1.3816.895.4217.4194.6816.8176.9005 1.3787.1653.4217.3617 1.056.4169 2.2263.0602 1.2655.0739 1.645.0796 4.848.0058 3.203-.0055 3.5834-.061 4.848-.051 1.17-.245 1.8055-.408 2.2294-.216.5604-.4763.96-.8954 1.3814-.419.4215-.8181.6811-1.3783.9-.4224.1649-1.0577.3617-2.2262.4174-1.2656.0595-1.6448.072-4.8493.079-3.2045.007-3.5825-.006-4.848-.0608M16.953 5.5864A1.44 1.44 0 1 0 18.39 4.144a1.44 1.44 0 0 0-1.437 1.4424M5.8385 12.012c.0067 3.4032 2.7706 6.1557 6.173 6.1493 3.4026-.0065 6.157-2.7701 6.1506-6.1733-.0065-3.4032-2.771-6.1565-6.174-6.1498-3.403.0067-6.156 2.771-6.1496 6.1738M8 12.0077a4 4 0 1 1 4.008 3.9921A3.9996 3.9996 0 0 1 8 12.0077"/>',
        'x' => '<path d="M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z"/>',
        'threads' => '<path d="M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.472 12.01v-.017c.03-3.579.879-6.43 2.525-8.482C5.845 1.205 8.6.024 12.18 0h.014c2.746.02 5.043.725 6.826 2.098 1.677 1.29 2.858 3.13 3.509 5.467l-2.04.569c-1.104-3.96-3.898-5.984-8.304-6.015-2.91.022-5.11.936-6.54 2.717C4.307 6.504 3.616 8.914 3.589 12c.027 3.086.718 5.496 2.057 7.164 1.43 1.783 3.631 2.698 6.54 2.717 2.623-.02 4.358-.631 5.8-2.045 1.647-1.613 1.618-3.593 1.09-4.798-.31-.71-.873-1.3-1.634-1.75-.192 1.352-.622 2.446-1.284 3.272-.886 1.102-2.14 1.704-3.73 1.79-1.202.065-2.361-.218-3.259-.801-1.063-.689-1.685-1.74-1.752-2.964-.065-1.19.408-2.285 1.33-3.082.88-.76 2.119-1.207 3.583-1.291a13.853 13.853 0 0 1 3.02.142c-.126-.742-.375-1.332-.75-1.757-.513-.586-1.308-.883-2.359-.89h-.029c-.844 0-1.992.232-2.721 1.32L7.734 7.847c.98-1.454 2.568-2.256 4.478-2.256h.044c3.194.02 5.097 1.975 5.287 5.388.108.046.216.094.321.142 1.49.7 2.58 1.761 3.154 3.07.797 1.82.871 4.79-1.548 7.158-1.85 1.81-4.094 2.628-7.277 2.65Zm1.003-11.69c-.242 0-.487.007-.739.021-1.836.103-2.98.946-2.916 2.143.067 1.256 1.452 1.839 2.784 1.767 1.224-.065 2.818-.543 3.086-3.71a10.5 10.5 0 0 0-2.215-.221z"/>',
        'pinterest' => '<path d="M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.618 11.162-.105-.949-.199-2.403.041-3.439.219-.937 1.406-5.957 1.406-5.957s-.359-.72-.359-1.781c0-1.663.967-2.911 2.168-2.911 1.024 0 1.518.769 1.518 1.688 0 1.029-.653 2.567-.992 3.992-.285 1.193.6 2.165 1.775 2.165 2.128 0 3.768-2.245 3.768-5.487 0-2.861-2.063-4.869-5.008-4.869-3.41 0-5.409 2.562-5.409 5.199 0 1.033.394 2.143.889 2.741.099.12.112.225.085.345-.09.375-.293 1.199-.334 1.363-.053.225-.172.271-.401.165-1.495-.69-2.433-2.878-2.433-4.646 0-3.776 2.748-7.252 7.92-7.252 4.158 0 7.392 2.967 7.392 6.923 0 4.135-2.607 7.462-6.233 7.462-1.214 0-2.354-.629-2.758-1.379l-.749 2.848c-.269 1.045-1.004 2.352-1.498 3.146 1.123.345 2.306.535 3.55.535 6.607 0 11.985-5.365 11.985-11.987C23.97 5.39 18.592.026 11.985.026L12.017 0z"/>',
    ];
    if ($name === 'xBrand') {
        $name = 'x';
    }
    if (isset($brand[$name])) {
        return '<svg class="' . $class . ' i-fill i-' . $name . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $brand[$name] . '</svg>';
    }
    static $p = [
        'home' => '<path d="m3 10 9-7 9 7v10a2 2 0 0 1-2 2h-4v-7H9v7H5a2 2 0 0 1-2-2z"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
        'book' => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2zM22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        'live' => '<circle cx="12" cy="12" r="2"/><path d="M16.24 7.76a6 6 0 0 1 0 8.49M7.76 16.24a6 6 0 0 1 0-8.49M19.07 4.93a10 10 0 0 1 0 14.14M4.93 19.07a10 10 0 0 1 0-14.14"/>',
        'clipboard' => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>',
        'file' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4M16 13H8M16 17H8"/>',
        'cart' => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2 2h3l2.7 12.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/>',
        'tag' => '<path d="M12.6 2.6A2 2 0 0 0 11.2 2H4a2 2 0 0 0-2 2v7.2a2 2 0 0 0 .6 1.4l8.7 8.7a2.4 2.4 0 0 0 3.4 0l6.6-6.6a2.4 2.4 0 0 0 0-3.4z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20M2 12h20"/>',
        'chart' => '<path d="M3 3v18h18"/><path d="M7 16v-4M12 16V8M17 16v-7"/>',
        'megaphone' => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'headset' => '<path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"/>',
        'award' => '<circle cx="12" cy="8" r="6"/><path d="M15.48 12.89 17 22l-5-3-5 3 1.52-9.11"/>',
        'settings' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'log' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M9 13h6M9 17h3"/>',
        'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'chev' => '<path d="m6 9 6 6 6-6"/>',
        'right' => '<path d="m9 18 6-6-6-6"/>',
        'arrow' => '<path d="M5 12h14M12 5l7 7-7 7"/>',
        'expand' => '<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'edit' => '<path d="M12 20h9M16.5 3.5a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4Z"/>',
        'trash' => '<path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'eye' => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'rupee' => '<path d="M6 3h12M6 8h12M6 13l8.5 8M6 13h3c6.67 0 6.67-10 0-10"/>',
        'cap' => '<path d="M22 10 12 5 2 10l10 5 10-5Z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
        'external' => '<path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5M3 12a9 3 0 0 0 18 0"/>',
        'help' => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3M12 17h.01"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'star' => '<path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/>',
        'plug' => '<path d="M12 22v-5M9 8V2M15 8V2M18 8v5a6 6 0 0 1-12 0V8z"/>',
        'lock' => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21"/>',
        'copy' => '<rect x="8" y="8" width="14" height="14" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        'up' => '<path d="m18 15-6-6-6 6"/>',
        'down' => '<path d="m6 9 6 6 6-6"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l4 2"/>',
        'alert' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4M12 17h.01"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'key' => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        // Added for the redesigned shell, modules and new screens
        'video' => '<path d="m16 13 5.22 3.48a.5.5 0 0 0 .78-.42V7.94a.5.5 0 0 0-.76-.43L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/>',
        'invoice' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8M12 17.5v-11"/>',
        'quiz' => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01"/>',
        'sparkles' => '<path d="M9.94 14.06 8.5 19l-1.44-4.94L2 12.5l5.06-1.44L8.5 6l1.44 5.06L15 12.5z"/><path d="M18 3v4M20 5h-4M19 15v4M21 17h-4"/>',
        'newsletter' => '<path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8M15 18h-5M10 6h8v4h-8z"/>',
        'highlight' => '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
        'banner' => '<rect x="2" y="3" width="20" height="18" rx="2"/><path d="M2 9h20M7 14h10M9 17h6"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>',
        'panel' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/>',
        'moon' => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
        'palette' => '<circle cx="13.5" cy="6.5" r="1"/><circle cx="17.5" cy="10.5" r="1"/><circle cx="8.5" cy="7.5" r="1"/><circle cx="6.5" cy="12.5" r="1"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.93 0 1.65-.75 1.65-1.69 0-.44-.18-.84-.44-1.13-.29-.29-.44-.65-.44-1.13a1.64 1.64 0 0 1 1.67-1.67h2c3.05 0 5.56-2.5 5.56-5.55C21.97 6.01 17.46 2 12 2z"/>',
        'type' => '<path d="M4 7V4h16v3M9 20h6M12 4v16"/>',
        'layout' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
        'filter' => '<path d="M22 3H2l8 9.46V19l4 2v-8.54z"/>',
        'refresh' => '<path d="M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M21 12a9 9 0 0 1-15 6.7L3 16M3 21v-5h5"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'close' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'send' => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
        'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'trending' => '<path d="m22 7-8.5 8.5-5-5L2 17"/><path d="M16 7h6v6"/>',
        'folder' => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
        'chat' => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
        'ticket' => '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2M13 17v2M13 11v2"/>',
        'percent' => '<path d="M19 5 5 19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
        'layers' => '<path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65M22 12.65l-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
        'server' => '<rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><path d="M6 6h.01M6 18h.01"/>',
        'monitor' => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'sliders' => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
        'target' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'code' => '<path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/>',
        'flag' => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1zM4 22v-7"/>',
        'trophy' => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18M4 22h16M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22M18 2H6v7a6 6 0 0 0 12 0V2Z"/>',
        'unlock' => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>',
        'play' => '<path d="M6 3l14 9-14 9V3z"/>',
        'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'more' => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
        'left' => '<path d="m15 18-6-6 6-6"/>',
        'activity' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'building' => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4M8 6h.01M16 6h.01M12 6h.01M12 10h.01M12 14h.01M16 10h.01M16 14h.01M8 10h.01M8 14h.01"/>',
        'navigation' => '<path d="M3 11 22 2l-9 19-2-8-8-2Z"/>',
        'share' => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.59 13.51 6.83 3.98M15.41 6.51l-6.82 3.98"/>',
        'gift' => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7M7.5 8a2.5 2.5 0 0 1 0-5C10 3 12 8 12 8s2-5 4.5-5a2.5 2.5 0 0 1 0 5"/>',
        'wrench' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'rocket' => '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09zM12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>',
        'eraser' => '<path d="m7 21-4.3-4.3c-1-1-1-2.5 0-3.4l9.6-9.6c1-1 2.5-1 3.4 0l5.6 5.6c1 1 1 2.5 0 3.4L13 21"/><path d="M22 21H7M5 11l9 9"/>',
        'languages' => '<path d="m5 8 6 6M4 14l6-6 2-3M2 5h12M7 2h1M22 22l-5-10-5 10M14 18h6"/>',
        'gauge' => '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
        'zap' => '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
        'receipt' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M8 7h8M8 11h8M8 15h5"/>',
    ];
    return '<svg class="' . $class . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ($p[$name] ?? $p['file']) . '</svg>';
}

// ------------------------------------------------------------------
// Flash messages, redirects, POST handling
// ------------------------------------------------------------------
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [$type, $message];
}

function render_flash(): void
{
    $icons = ['success' => 'check-circle', 'error' => 'alert', 'warning' => 'alert', 'info' => 'info'];
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    if (!$flashes) {
        return;
    }
    echo '<div class="flashes">';
    foreach ($flashes as [$type, $message]) {
        // Inline alert (works without JS); admin.js adds the close button and fades success/info out after a few seconds.
        echo '<div class="alert alert-' . e($type) . ' flash" role="' . ($type === 'error' ? 'alert' : 'status') . '" data-flash="' . e($type) . '">'
            . icon($icons[$type] ?? 'info') . '<span class="flash-text">' . e($message) . '</span></div>';
    }
    echo '</div>';
}

function redirect_to(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

/** Current page URL with some query parameters replaced (null removes). */
function url_with(array $changes, ?string $base = null): string
{
    $q = array_merge($_GET, $changes);
    $q = array_filter($q, fn($v) => $v !== null && $v !== '');
    return ($base ?? strtok($_SERVER['REQUEST_URI'], '?')) . ($q ? '?' . http_build_query($q) : '');
}

/** True for a POST with a valid CSRF token (aborts on a bad token). */
function admin_post(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return false;
    }
    require_csrf();
    return true;
}

function admin_url(string $page = ''): string
{
    return app_url('admin/' . $page);
}

// ------------------------------------------------------------------
// Small renderers
// ------------------------------------------------------------------
function money(float|int|string|null $v): string
{
    return '₹' . number_format((float) $v, ((float) $v == floor((float) $v)) ? 0 : 2);
}

function fmt_dt(?string $v, string $format = 'j M Y, g:i A'): string
{
    return $v ? date($format, strtotime($v)) : '—';
}

function badge(?string $status): string
{
    $map = [
        'green' => ['active', 'published', 'paid', 'open', 'approved', 'captured', 'processed', 'converted', 'resolved', 'subscribed', 'hired', 'scheduled', 'submitted', 'success', 'verified', 'sent', 'live', 'issued', 'passed', '1'],
        'amber' => ['pending', 'draft', 'new', 'follow_up', 'in_progress', 'waiting', 'requested', 'processing', 'shortlisted', 'interview', 'created', 'authorized', 'queued', 'sending', 'retrying', 'upcoming'],
        'red' => ['inactive', 'failed', 'rejected', 'cancelled', 'spam', 'revoked', 'deleted', 'critical', 'closed', 'unsubscribed', 'void', '0'],
        'blue' => ['refunded', 'partially_refunded', 'contacted', 'completed', 'auto_submitted', 'info', 'skipped'],
        'purple' => ['warning', 'archived', 'expired'],
    ];
    $tone = 'gray';
    foreach ($map as $t => $list) {
        if (in_array((string) $status, $list, true)) {
            $tone = $t;
            break;
        }
    }
    $label = match ((string) $status) { '1' => 'Yes', '0' => 'No', default => ucwords(str_replace('_', ' ', (string) $status)) };
    return '<span class="badge b-' . $tone . '">' . e($label) . '</span>';
}

function demo_badge(array $row): string
{
    return !empty($row['is_demo']) ? ' <span class="badge b-demo">Demo</span>' : '';
}

/** Pagination footer (nothing at all for an empty list - the empty state says it). */
function render_pager(int $total, int $page, int $per): void
{
    if ($total <= 0) {
        return;
    }
    $per = max(1, $per);
    $pages = max(1, (int) ceil($total / $per));
    $from = $total ? ($page - 1) * $per + 1 : 0;
    echo '<div class="pager"><span>Showing <strong>' . number_format($from) . '–' . number_format(min($total, $page * $per)) . '</strong> of ' . number_format($total) . '</span>';
    if ($pages > 1) {
        echo '<nav class="pages" aria-label="Pagination">';
        echo $page > 1 ? '<a href="' . e(url_with(['page' => $page - 1])) . '" rel="prev" aria-label="Previous page">' . icon('left') . '</a>' : '<span class="off" aria-hidden="true">' . icon('left') . '</span>';
        $start = max(1, $page - 2);
        $end = min($pages, $page + 2);
        if ($start > 1) {
            echo '<a href="' . e(url_with(['page' => 1])) . '">1</a>' . ($start > 2 ? '<span class="gap" aria-hidden="true">…</span>' : '');
        }
        for ($p = $start; $p <= $end; $p++) {
            echo $p === $page ? '<span class="cur" aria-current="page">' . $p . '</span>' : '<a href="' . e(url_with(['page' => $p])) . '">' . $p . '</a>';
        }
        if ($end < $pages) {
            echo ($end < $pages - 1 ? '<span class="gap" aria-hidden="true">…</span>' : '') . '<a href="' . e(url_with(['page' => $pages])) . '">' . $pages . '</a>';
        }
        echo $page < $pages ? '<a href="' . e(url_with(['page' => $page + 1])) . '" rel="next" aria-label="Next page">' . icon('right') . '</a>' : '<span class="off" aria-hidden="true">' . icon('right') . '</span>';
        echo '</nav>';
    }
    echo '</div>';
}

/** Stream rows as a CSV download (Excel-friendly UTF-8 with BOM). Neutralises formula injection. */
function csv_download(string $filename, array $header, array $rows): never
{
    audit_log('csv_export', 'report', $filename);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header, ',', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($out, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v, array_values($r)), ',', '"', '\\');
    }
    fclose($out);
    exit;
}

/**
 * Pill tab strip linking to ?tab=key (server-side tabs).
 *
 *   admin_tabs(['posts' => 'Posts', 'categories' => 'Categories'], $tab);              // as before
 *   admin_tabs(['all' => 'All · 12', 'live' => 'Live (3)'], $tab);                       // trailing counts become badges
 *   admin_tabs(['lessons' => ['label' => 'Lessons', 'icon' => 'book', 'count' => 14]], $tab, ['keep' => ['course']]);
 *
 * Tab value: a label string, or ['label', 'icon', 'count', 'href', 'tone' (red|amber|green|blue)].
 * $opts: 'keep' => query params carried into every tab link, 'icons' => [key => icon], 'label' => aria label,
 *        'param' => query param name (default 'tab'), 'covered' => false to always show the strip.
 *
 * When the module tab bar on this page already links every tab (the first tab, the page's default view,
 * also matches the plain page link), the strip is marked "is-covered" and hidden: it would only repeat
 * the bar. Its counts are moved onto the bar by admin.js.
 */
function admin_tabs(array $tabs, string $current, array $opts = []): string
{
    $param = (string) ($opts['param'] ?? 'tab');
    $keep = [];
    foreach ((array) ($opts['keep'] ?? []) as $k) {
        if (isset($_GET[$k]) && is_scalar($_GET[$k]) && (string) $_GET[$k] !== '') {
            $keep[$k] = (string) $_GET[$k];
        }
    }
    // Module tab bar links on this page, by comparable href ('page.php?tab=x').
    $modLinks = [];
    if (($opts['covered'] ?? true) !== false && function_exists('module_tab_hrefs')) {
        foreach (module_tab_hrefs() as $linkHref => $barHref) {
            if (($mk = menu_href_key((string) $linkHref)) !== null) {
                $modLinks[$mk] ??= $barHref;
            }
        }
    }
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $items = [];
    $first = true;
    foreach ($tabs as $key => $def) {
        $key = (string) $key;
        $def = is_array($def) ? $def : ['label' => (string) $def];
        $label = (string) ($def['label'] ?? $key);
        $count = $def['count'] ?? null;
        // "All · 12" / "Mock Tests (3)" → label + count badge
        if ($count === null && preg_match('/^(.*?)\s*(?:·\s*([\d,]+)|\(([\d,]+)\))$/u', $label, $m) && trim($m[1]) !== '') {
            $label = trim($m[1]);
            $count = ($m[2] ?? '') !== '' ? $m[2] : $m[3];
        }
        $href = (string) ($def['href'] ?? ('?' . http_build_query([$param => $key] + $keep)));
        $cover = null;
        if ($modLinks) {
            $cands = [menu_href_key($href)];
            if ($first && !isset($def['href']) && !$keep) {
                $cands[] = $script; // default tab = the plain page link
            }
            foreach ($cands as $c) {
                if ($c !== null && isset($modLinks[$c])) {
                    $cover = $modLinks[$c];
                    break;
                }
            }
        }
        $items[] = [$key, $label, $count, $def['icon'] ?? ($opts['icons'][$key] ?? null), $href, $def['tone'] ?? null, $cover];
        $first = false;
    }
    $covered = $items && $modLinks && !in_array(null, array_column($items, 6), true);
    $h = '<nav class="tabs' . ($covered ? ' is-covered' : '') . '" aria-label="' . e((string) ($opts['label'] ?? 'Sections')) . '" data-scroll-fade><div class="tabs-track">';
    foreach ($items as [$key, $label, $count, $ico, $href, $tone, $cover]) {
        $on = $key === $current;
        $h .= '<a href="' . e($href) . '"' . ($on ? ' class="on" aria-current="page"' : '') . ($covered ? ' data-cover-href="' . e(menu_href($cover)) . '"' : '') . '>'
            . ($ico ? icon((string) $ico) : '') . '<span>' . e($label) . '</span>'
            . ($count !== null && (string) $count !== '' ? '<span class="tab-count' . ($tone ? ' tc-' . e((string) $tone) : '') . '">' . e((string) $count) . '</span>' : '')
            . '</a>';
    }
    return $h . '</div></nav>';
}

function current_tab(array $tabs): string
{
    $t = (string) ($_GET['tab'] ?? '');
    return array_key_exists($t, $tabs) ? $t : (string) array_key_first($tabs);
}

/** Hidden CSRF + optional method fields for inline action forms. */
function action_form(string $action, array $hidden, string $label, string $class = 'btn btn-light btn-sm', ?string $confirm = null, ?string $title = null): string
{
    $h = csrf_field() . '<input type="hidden" name="_action" value="' . e($action) . '">';
    foreach ($hidden as $k => $v) {
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
    }
    // $title names an icon-only button (tooltip + accessible name)
    return '<form method="post" class="inline-form">' . $h . '<button class="' . $class . '"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '')
        . ($title ? ' title="' . e($title) . '" aria-label="' . e($title) . '"' : '') . '>' . $label . '</button></form>';
}

/**
 * Row actions for list tables: a few visible icon buttons + a "⋯" overflow menu that is never
 * clipped by the scrolling table (native popover, positioned by admin.js).
 *
 *   echo row_actions([
 *       ['Edit', 'courses.php?action=edit&id=5', 'edit'],                                   // link (shown as an icon button)
 *       ['View on site', app_url('courses/x'), 'external', 'menu' => true, 'blank' => true], // link inside the ⋯ menu
 *       ['Duplicate', 'post' => ['duplicate', ['id' => 5]], 'icon' => 'copy', 'menu' => true],
 *       ['Delete', 'post' => ['delete', ['id' => 5]], 'icon' => 'trash', 'menu' => true, 'danger' => true, 'confirm' => 'Delete this course?'],
 *   ]);
 *
 * Item: [label, href, icon] or keys label/href/icon/post [action, hidden fields]/menu/danger/confirm/blank.
 * Items without 'menu' => true are icon buttons (label = tooltip + aria-label); the rest go in the menu.
 */
function row_actions(array $items, string $menuLabel = 'More actions'): string
{
    static $n = 0;
    $inline = '';
    $menu = '';
    foreach ($items as $it) {
        $label = (string) ($it['label'] ?? $it[0] ?? '');
        $href = $it['href'] ?? $it[1] ?? null;
        $ico = (string) ($it['icon'] ?? $it[2] ?? 'right');
        $danger = !empty($it['danger']);
        $confirm = isset($it['confirm']) ? ' data-confirm="' . e((string) $it['confirm']) . '"' : '';
        $inMenu = !empty($it['menu']);
        $cls = $inMenu ? ($danger ? ' class="danger"' : '') : ' class="ra-btn' . ($danger ? ' danger' : '') . '" title="' . e($label) . '" aria-label="' . e($label) . '"';
        $inner = icon($ico) . ($inMenu ? '<span>' . e($label) . '</span>' : '');
        if (isset($it['post'])) {
            [$action, $hidden] = $it['post'] + [1 => []];
            $h = csrf_field() . '<input type="hidden" name="_action" value="' . e((string) $action) . '">';
            foreach ((array) $hidden as $k => $v) {
                $h .= '<input type="hidden" name="' . e((string) $k) . '" value="' . e((string) $v) . '">';
            }
            $html = '<form method="post" class="inline-form">' . $h . '<button type="submit"' . $cls . $confirm . ($inMenu ? ' role="menuitem"' : '') . '>' . $inner . '</button></form>';
        } else {
            $html = '<a href="' . e((string) $href) . '"' . $cls . $confirm . ($inMenu ? ' role="menuitem"' : '') . (!empty($it['blank']) ? ' target="_blank" rel="noopener"' : '') . '>' . $inner . '</a>';
        }
        $inMenu ? $menu .= $html : $inline .= $html;
    }
    if ($menu !== '') {
        $id = 'ra-menu-' . ++$n;
        $inline .= '<button type="button" class="ra-btn ra-more" popovertarget="' . $id . '" aria-haspopup="menu" title="' . e($menuLabel) . '" aria-label="' . e($menuLabel) . '">' . icon('more') . '</button>'
            . '<div class="row-menu" id="' . $id . '" popover role="menu" aria-label="' . e($menuLabel) . '">' . $menu . '</div>';
    }
    return '<div class="row-acts">' . $inline . '</div>';
}

/** Friendly empty state for a card or table: icon, title, one line of help and an optional real action. */
function empty_state(string $title, string $text = '', string $icon = 'inbox', ?string $href = null, ?string $label = null): string
{
    return '<div class="empty"><span class="empty-ic">' . icon($icon) . '</span><strong>' . e($title) . '</strong>'
        . ($text !== '' ? '<p>' . e($text) . '</p>' : '')
        . ($href && $label ? '<a class="btn btn-primary btn-sm" href="' . e($href) . '">' . icon('plus') . ' ' . e($label) . '</a>' : '') . '</div>';
}

// ------------------------------------------------------------------
// Charts (server-rendered SVG; tooltips via data-tip in admin.js)
// Single y-axis only; thin marks; 4px rounded bar tops; 2px lines; table fallback.
// ------------------------------------------------------------------
/** Axis maximum giving 4 "nice" whole-number steps (1, 2, 5 × 10^n). */
function nice_max(float $v): float
{
    $step = max(1, $v / 4);
    $exp = 10 ** floor(log10($step));
    foreach ([1, 2, 5, 10] as $m) {
        if ($m * $exp >= $step) {
            return 4 * $m * $exp;
        }
    }
    return 40 * $exp;
}

function short_num(float $v, bool $rupee = false): string
{
    $s = match (true) {
        $v >= 10_000_000 => round($v / 10_000_000, 1) . 'Cr',
        $v >= 100_000 => round($v / 100_000, 1) . 'L',
        $v >= 1_000 => round($v / 1_000, 1) . 'K',
        default => (string) round($v),
    };
    return ($rupee ? '₹' : '') . $s;
}

/** @param array<string,float> $points label => value */
/** $W/$H set the drawing's aspect: wide cards pass a wider viewBox so text stays at its normal size. */
function svg_bar_chart(array $points, string $title, bool $rupee = false, int $W = 480, int $H = 240): string
{
    [$L, $B, $T] = [52, 30, 12];
    $max = nice_max(max([0, ...array_values($points)]));
    $n = max(1, count($points));
    $slot = ($W - $L - 8) / $n;
    $bw = min(28, $slot - 2 * 2);
    $plotH = $H - $B - $T;
    $svg = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($title) . '">';
    for ($i = 0; $i <= 4; $i++) {
        $y = $T + $plotH - $plotH * $i / 4;
        $svg .= '<line class="grid-line" x1="' . $L . '" x2="' . $W . '" y1="' . $y . '" y2="' . $y . '"/>';
        $svg .= '<text class="axis-text" x="' . ($L - 8) . '" y="' . ($y + 4) . '" text-anchor="end">' . e(short_num($max * $i / 4, $rupee)) . '</text>';
    }
    $i = 0;
    foreach ($points as $label => $value) {
        $x = $L + $slot * $i + ($slot - $bw) / 2;
        $h = $max > 0 ? $plotH * $value / $max : 0;
        $y = $T + $plotH - $h;
        $tip = $label . ': ' . ($rupee ? money($value) : number_format($value));
        if ($h > 0.5) {
            $r = min(4, $h, $bw / 2);
            $svg .= '<path class="bar" data-tip="' . e($tip) . '" d="M' . round($x, 1) . ',' . round($T + $plotH, 1) . 'V' . round($y + $r, 1) . 'Q' . round($x, 1) . ',' . round($y, 1) . ' ' . round($x + $r, 1) . ',' . round($y, 1) . 'H' . round($x + $bw - $r, 1) . 'Q' . round($x + $bw, 1) . ',' . round($y, 1) . ' ' . round($x + $bw, 1) . ',' . round($y + $r, 1) . 'V' . round($T + $plotH, 1) . 'Z"/>';
        }
        $svg .= '<rect class="hit" data-tip="' . e($tip) . '" x="' . round($L + $slot * $i, 1) . '" y="' . $T . '" width="' . round($slot, 1) . '" height="' . $plotH . '"/>';
        $svg .= '<text class="axis-text" x="' . round($x + $bw / 2, 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . e($label) . '</text>';
        $i++;
    }
    return $svg . '</svg>' . chart_table($title, array_keys($points), [[$title, array_values($points)]], $rupee);
}

/** @param array<int,array{0:string,1:string,2:array<float>}> $series [name, css-class-suffix (1|2), values] */
function svg_line_chart(array $labels, array $series, string $title, int $W = 480, int $H = 240): string
{
    [$L, $B, $T, $R] = [52, 30, 12, 30];
    $all = array_merge(...array_map(fn($s) => $s[2], $series));
    $max = nice_max(max([0, ...$all]));
    $n = max(2, count($labels));
    $plotW = $W - $L - $R;
    $plotH = $H - $B - $T;
    $px = fn($i) => $L + $plotW * $i / ($n - 1);
    $py = fn($v) => $T + $plotH - ($max > 0 ? $plotH * $v / $max : 0);
    $svg = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($title) . '">';
    for ($i = 0; $i <= 4; $i++) {
        $y = $T + $plotH - $plotH * $i / 4;
        $svg .= '<line class="grid-line" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $y . '" y2="' . $y . '"/>';
        $svg .= '<text class="axis-text" x="' . ($L - 8) . '" y="' . ($y + 4) . '" text-anchor="end">' . e(short_num($max * $i / 4)) . '</text>';
    }
    foreach ($labels as $i => $label) {
        $svg .= '<text class="axis-text" x="' . round($px($i), 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . e($label) . '</text>';
    }
    foreach ($series as [$name, $cls, $values]) {
        $d = '';
        foreach ($values as $i => $v) {
            $d .= ($i ? 'L' : 'M') . round($px($i), 1) . ',' . round($py($v), 1);
        }
        $svg .= '<path class="series s' . $cls . '" d="' . $d . '"/>';
        foreach ($values as $i => $v) {
            $svg .= '<circle class="pt p' . $cls . '" cx="' . round($px($i), 1) . '" cy="' . round($py($v), 1) . '" r="4"/>';
            $svg .= '<circle class="hit" data-tip="' . e($labels[$i] . ' · ' . $name . ': ' . number_format($v)) . '" cx="' . round($px($i), 1) . '" cy="' . round($py($v), 1) . '" r="11"/>';
        }
        $last = count($values) - 1;
        $svg .= '<text class="axis-text" x="' . round($px($last) + 8, 1) . '" y="' . round($py($values[$last]) + 4, 1) . '">' . e(number_format($values[$last])) . '</text>';
    }
    return $svg . '</svg>' . chart_table($title, $labels, array_map(fn($s) => [$s[0], $s[2]], $series), false);
}

function chart_table(string $title, array $labels, array $series, bool $rupee): string
{
    $h = '<details class="table-view"><summary>View as table</summary><div class="table-wrap"><table class="t"><caption class="sr-only">' . e($title) . '</caption><thead><tr><th>Period</th>';
    foreach ($series as [$name]) {
        $h .= '<th>' . e($name) . '</th>';
    }
    $h .= '</tr></thead><tbody>';
    foreach ($labels as $i => $label) {
        $h .= '<tr><td>' . e((string) $label) . '</td>';
        foreach ($series as [, $values]) {
            $h .= '<td>' . ($rupee ? money($values[$i]) : number_format($values[$i])) . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div></details>';
}

/** "5 minutes ago" style relative time for a stored DATETIME. */
function fmt_ago(?string $at): string
{
    if (!$at) {
        return 'never';
    }
    $s = max(0, time() - (int) strtotime($at));
    return match (true) {
        $s < 90 => 'just now',
        $s < 5400 => round($s / 60) . ' min ago',
        $s < 129600 => round($s / 3600) . ' hours ago',
        $s < 172800 => '1 day ago',
        default => round($s / 86400) . ' days ago',
    };
}

/** Last N calendar months as ['Y-m' => 'Mon'] */
function last_months(int $n = 12): array
{
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $t = strtotime(date('Y-m-01') . " -{$i} months");
        $out[date('Y-m', $t)] = date('M', $t);
    }
    return $out;
}

/** "1 student" / "3 students" (pass $plural for irregular words: plural_n(2, 'enquiry', 'enquiries')). */
function plural_n(int|float $n, string $singular, ?string $plural = null): string
{
    return number_format((float) $n, $n == floor((float) $n) ? 0 : 1) . ' ' . ((float) $n === 1.0 ? $singular : ($plural ?? $singular . 's'));
}

// ------------------------------------------------------------------
// Readable audit-log labels (Audit Trail, Staff Activity report)
// ------------------------------------------------------------------

/** Audit areas (audit_logs.entity_type) → [plural label, singular label]. */
const AUDIT_ENTITY_LABELS = [
    'api_keys' => ['API keys', 'API key'], 'backup' => ['Backups', 'Backup'], 'blog_posts' => ['Blog posts', 'Blog post'],
    'broken_links' => ['Broken links', 'Broken link'], 'cache' => ['Cache', 'Cache'], 'canned_responses' => ['Saved replies', 'Saved reply'],
    'certificates' => ['Certificates', 'Certificate'], 'cms_sections' => ['Website sections', 'Website section'], 'coupons' => ['Coupons', 'Coupon'],
    'course_categories' => ['Course categories', 'Course category'], 'course_enrolments' => ['Enrolments', 'Enrolment'],
    'course_reviews' => ['Course reviews', 'Course review'], 'course_sections' => ['Course sections', 'Course section'], 'courses' => ['Courses', 'Course'],
    'deploy' => ['Deployment', 'Deployment'], 'email_log' => ['Email log', 'Email'], 'enquiries' => ['Enquiries', 'Enquiry'],
    'exam_attempts' => ['Test attempts', 'Test attempt'], 'exam_questions' => ['Questions', 'Question'], 'exams' => ['Mock tests', 'Mock test'],
    'gallery_albums' => ['Gallery albums', 'Gallery album'], 'instructors' => ['Faculty', 'Faculty member'], 'integration' => ['Integrations', 'Integration'],
    'job_alerts' => ['Job alerts', 'Job alert'], 'job_applications' => ['Job applications', 'Job application'],
    'learning_resources' => ['Resources', 'Resource'], 'lessons' => ['Lessons', 'Lesson'], 'live_classes' => ['Live classes', 'Live class'],
    'lookup_values' => ['Lookup values', 'Lookup value'], 'media' => ['Media files', 'Media file'], 'media_videos' => ['Videos', 'Video'],
    'newsletter_subscribers' => ['Newsletter subscribers', 'Newsletter subscriber'], 'notification_templates' => ['Email templates', 'Email template'],
    'notifications' => ['Notifications', 'Notification'], 'order' => ['Orders', 'Order'], 'orders' => ['Orders', 'Order'], 'pages' => ['Pages', 'Page'],
    'permission' => ['Permissions', 'Permission'], 'popups' => ['Popups', 'Popup'], 'promo_banners' => ['Banners', 'Banner'],
    'report' => ['Reports', 'Report'], 'resource_categories' => ['Resource categories', 'Resource category'],
    'schema_migrations' => ['Database updates', 'Database update'], 'site_highlights' => ['Highlights', 'Highlight'],
    'site_settings' => ['Settings', 'Setting'], 'testimonials' => ['Testimonials', 'Testimonial'], 'user' => ['Users', 'User'], 'users' => ['Users', 'User'],
    'webhook' => ['Webhooks', 'Webhook'], 'launch' => ['Go-live checklist', 'Go-live step'],
];

/** Audit actions whose words alone read badly. Everything else is humanised by audit_action_label(). */
const AUDIT_ACTION_LABELS = [
    '2fa_enabled' => '2FA enabled', '2fa_disabled' => '2FA disabled', 'admin_2fa_failed' => 'Admin 2FA code failed',
    'admin_created_cli' => 'Admin created (command line)', 'admin_login_recovery_code' => 'Admin sign-in with a recovery code',
    'admin_login' => 'Admin sign-in', 'admin_logout' => 'Admin sign-out', 'admin_login_failed' => 'Admin sign-in failed',
    'admin_password_reset_requested' => 'Staff password reset requested', 'admin_password_reset_throttled' => 'Staff password reset (rate limited)',
    'admin_password_reset_unknown' => 'Staff password reset (unknown email)', 'admin_access_blocked' => 'Admin access blocked',
    'whatsapp_opt_in' => 'WhatsApp opt-in', 'whatsapp_opt_out' => 'WhatsApp opt-out', 'csv_export' => 'CSV export',
    'gsc_settings_saved' => 'Search Console settings saved', 'appearance_remove_favicon' => 'Favicon removed', 'appearance_remove_logo' => 'Logo removed',
    'bulk_updated' => 'Bulk update', 'bulk_deleted' => 'Bulk delete', 'obsolete_file_removed' => 'Old file removed',
];

/** "media_videos" → "Videos" (or "Video" with $singular). Unknown areas are humanised. */
function audit_entity_label(?string $entity, bool $singular = false): string
{
    $entity = (string) $entity;
    if (isset(AUDIT_ENTITY_LABELS[$entity])) {
        return AUDIT_ENTITY_LABELS[$entity][$singular ? 1 : 0];
    }
    return ucfirst(str_replace(['_', '.'], ' ', $entity));
}

/**
 * Readable audit action: "whatsapp_opt_in" → "WhatsApp opt-in", "2fa_enabled" → "2FA enabled".
 * With $entity, the generic CRUD actions name the item: created + media_videos → "Video added".
 */
function audit_action_label(string $action, ?string $entity = null): string
{
    if ($entity !== null && $entity !== '') {
        $generic = ['created' => '%s added', 'updated' => '%s updated', 'deleted' => '%s deleted'];
        if (isset($generic[$action])) {
            return sprintf($generic[$action], audit_entity_label($entity, true));
        }
        if ($action === 'bulk_updated' || $action === 'bulk_deleted') {
            return audit_entity_label($entity) . ($action === 'bulk_updated' ? ' updated in bulk' : ' deleted in bulk');
        }
    }
    if (isset(AUDIT_ACTION_LABELS[$action])) {
        return AUDIT_ACTION_LABELS[$action];
    }
    static $words = ['2fa' => '2FA', 'api' => 'API', 'cms' => 'CMS', 'csv' => 'CSV', 'smtp' => 'SMTP', 'seo' => 'SEO', 'gsc' => 'Search Console',
        'whatsapp' => 'WhatsApp', 'youtube' => 'YouTube', 'otp' => 'OTP', 'ip' => 'IP', 'url' => 'URL', 'pdf' => 'PDF', 'cli' => 'CLI', 'totp' => 'TOTP'];
    $parts = array_map(fn($w) => $words[strtolower($w)] ?? strtolower($w), preg_split('/[_.]+/', $action) ?: [$action]);
    $label = implode(' ', $parts);
    return $label === '' ? $action : strtoupper(substr($label, 0, 1)) . substr($label, 1);
}

// ------------------------------------------------------------------
// Academic Session (Indian academic / financial year: 1 April - 31 March)
// The top bar selector stores the choice per admin (PHP session + a per-user cookie);
// the dashboard and report screens default their date range to it.
// ------------------------------------------------------------------
/** Session key such as '2026-27' for a timestamp (default: now). */
function academic_session_key(?int $ts = null): string
{
    $ts ??= time();
    $y = (int) date('Y', $ts);
    $start = (int) date('n', $ts) >= 4 ? $y : $y - 1;
    return $start . '-' . substr((string) ($start + 1), -2);
}

/** ['key', 'label', 'from' (Y-m-d), 'to' (Y-m-d), 'current' (bool), 'upcoming' (bool)] for a key like '2025-26', or null when malformed. */
function academic_session_range(string $key): ?array
{
    if (!preg_match('/^(20\d\d)-(\d\d)$/', $key, $m) || (int) $m[2] !== ((int) $m[1] + 1) % 100) {
        return null;
    }
    $y = (int) $m[1];
    return ['key' => $key, 'label' => $key, 'from' => $y . '-04-01', 'to' => ($y + 1) . '-03-31',
        'current' => $key === academic_session_key(), 'upcoming' => $y . '-04-01' > date('Y-m-d')];
}

/** Sessions a course batch can be tagged with: last, current and the next two. ['2027-28' => '2027-28 (Apr 2027 – Mar 2028)', ...] */
function academic_session_choices(): array
{
    $current = (int) substr(academic_session_key(), 0, 4);
    $out = [];
    foreach (range($current - 1, $current + 2) as $y) {
        $s = academic_session_range($y . '-' . substr((string) ($y + 1), -2));
        $out[$s['key']] = $s['key'] . ' (' . academic_session_span($s) . ')' . ($s['current'] ? ' - current' : ($s['upcoming'] ? ' - next' : ''));
    }
    return $out;
}

/**
 * Sessions offered in the selector: every session that holds data (students, enrolments, orders,
 * enquiries, test attempts) plus the current one, newest first - and an upcoming session as soon as a
 * course batch is tagged with it (admissions open before 1 April). Cached in the PHP session for 10 minutes.
 * @return array<int,array{key:string,label:string,from:string,to:string,current:bool}>
 */
function admin_session_options(): array
{
    static $opts = null;
    if ($opts !== null) {
        return $opts;
    }
    $cache = $_SESSION['__academic_sessions'] ?? null;
    if (is_array($cache) && ($cache['at'] ?? 0) > time() - 600 && is_array($cache['years'] ?? null)) {
        $years = $cache['years'];
    } else {
        $years = [];
        $session = fn(string $col) => "IF(MONTH({$col}) >= 4, YEAR({$col}), YEAR({$col}) - 1)";
        foreach ([['users', 'created_at', "user_type = 'student'"], ['course_enrolments', 'enrolled_at', '1=1'], ['orders', 'created_at', '1=1'],
                  ['enquiries', 'created_at', '1=1'], ['exam_attempts', 'started_at', '1=1']] as [$table, $col, $where]) {
            try {
                foreach (db_all("SELECT DISTINCT {$session($col)} AS y FROM {$table} WHERE {$where} AND {$col} IS NOT NULL") as $r) {
                    $years[(int) $r['y']] = true;
                }
            } catch (PDOException) {
                // table not migrated yet
            }
        }
        $years = array_keys($years);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['__academic_sessions'] = ['at' => time(), 'years' => $years];
        }
    }
    // Batch sessions are read fresh (one small indexed query) so a newly tagged batch shows up for every admin at once.
    $batches = [];
    try {
        foreach (db_all('SELECT DISTINCT academic_session AS s FROM courses WHERE academic_session IS NOT NULL') as $r) {
            if ($range = academic_session_range((string) $r['s'])) {
                $batches[] = (int) substr($range['key'], 0, 4);
            }
        }
    } catch (PDOException) {
        // migration 010 not applied yet
    }
    $current = (int) substr(academic_session_key(), 0, 4);
    // Past sessions with data, the current one, and future sessions only when a batch course is tagged with them.
    $years = array_filter(array_unique([...$years, $current, ...$batches]), fn($y) => $y >= 2000 && ($y <= $current || in_array($y, $batches, true)));
    rsort($years);
    $opts = [];
    foreach (array_slice($years, 0, 12) as $y) {
        $opts[] = academic_session_range($y . '-' . substr((string) ($y + 1), -2));
    }
    return $opts;
}

/** Cookie that remembers the chosen session for one staff member on this browser. */
function admin_session_cookie(): string
{
    return 'lx_acad_session_' . (int) (current_user()['id'] ?? 0);
}

/**
 * The staff member's Academic Session: ['key' => '2026-27', 'label' => '2026-27', 'from' => '2026-04-01', 'to' => '2027-03-31', 'current' => bool].
 * Default: the current session. Report screens use it as their default date range.
 */
function admin_session(): array
{
    static $s = null;
    if ($s !== null) {
        return $s;
    }
    // Read the session choice only once the PHP session is running (a page may call this before admin_start()).
    start_secure_session();
    $want = (string) ($_SESSION['academic_session'] ?? ($_COOKIE[admin_session_cookie()] ?? ''));
    if ($want !== '' && in_array($want, array_column(admin_session_options(), 'key'), true)) {
        return $s = academic_session_range($want);
    }
    return $s = academic_session_range(academic_session_key());
}

/** Store the staff member's session choice (validated against the offered list). */
function admin_set_session(string $key): bool
{
    if (!in_array($key, array_column(admin_session_options(), 'key'), true)) {
        return false;
    }
    $_SESSION['academic_session'] = $key;
    $params = session_get_cookie_params();
    setcookie(admin_session_cookie(), $key, [
        'expires' => time() + 365 * 86400, 'path' => $params['path'] ?: '/', 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax',
    ]);
    return true;
}

/** "Apr 2026 – Mar 2027" */
function academic_session_span(array $s): string
{
    return date('M Y', strtotime($s['from'])) . ' – ' . date('M Y', strtotime($s['to']));
}

// ------------------------------------------------------------------
// Page shell
// ------------------------------------------------------------------
/**
 * Things waiting for staff attention (bell menu), each only for staff who may act on it.
 * @return array<int,array{0:string,1:string,2:string,3:int,4:string}> [label, href, icon, count, tone]
 */
function admin_attention_items(): array
{
    static $items = null;
    if ($items !== null) {
        return $items;
    }
    $items = [];
    $checks = [
        ['enquiries.view', 'New enquiries', 'enquiries.php?status=new', 'inbox', "SELECT COUNT(*) FROM enquiries WHERE status = 'new'", 'blue'],
        ['testimonials.manage', 'Testimonials to approve', 'testimonials.php?status=pending', 'star', "SELECT COUNT(*) FROM testimonials WHERE status = 'pending'", 'amber'],
        ['courses.manage', 'Course reviews to approve', 'reviews.php?status=pending', 'star', "SELECT COUNT(*) FROM course_reviews WHERE status = 'pending'", 'amber'],
        ['cms.manage', 'New job applications', 'jobs.php?tab=applications&status=new', 'briefcase', "SELECT COUNT(*) FROM job_applications WHERE status = 'new'", 'purple'],
        ['support.manage', 'Open support tickets', 'support.php?status=open', 'headset', "SELECT COUNT(*) FROM support_tickets WHERE status = 'open'", 'pink'],
        ['doubts.manage', 'Doubts waiting for an answer', 'doubts.php', 'chat', "SELECT COUNT(*) FROM doubts WHERE status = 'open'", 'purple'],
        ['payments.view', 'Refunds in progress', 'payments.php?tab=refunds', 'refresh', "SELECT COUNT(*) FROM refunds WHERE status IN ('requested','processing')", 'orange'],
        ['notifications.send', 'Failed emails', 'email-logs.php?status=failed', 'mail', "SELECT COUNT(*) FROM email_log WHERE status = 'failed'", 'red'],
    ];
    foreach ($checks as [$perm, $label, $href, $ico, $sql, $tone]) {
        if (!can($perm)) {
            continue;
        }
        try {
            $n = (int) db_value($sql);
        } catch (PDOException) {
            continue; // table not migrated yet
        }
        if ($n > 0) {
            $items[] = [$label, $href, $ico, $n, $tone];
        }
    }
    if (can('settings.manage') && is_file(APP_ROOT . '/includes/migrations.php')) {
        require_once APP_ROOT . '/includes/migrations.php';
        try {
            $pending = function_exists('pending_migrations') ? count(pending_migrations()) : 0;
        } catch (Throwable) {
            $pending = 0;
        }
        if ($pending) {
            $items[] = ['Database update needed', 'updates.php', 'database', $pending, 'red'];
        }
    }
    return $items;
}

/** Admin brand images: [wide logo URL, square 192px icon URL, 32px favicon URL] (uploaded in Appearance, else the bundled files). */
function admin_brand_images(): array
{
    $logo = (string) setting('logo_path', '');
    $fav = (string) setting('favicon_path', '');
    return [
        $logo !== '' && ($u = public_file_url($logo)) ? $u : app_url('assets/logo.png'),
        preg_match('#^uploads/branding/favicon-[a-f0-9]{8}$#', $fav) ? app_url($fav . '-192.png') : app_url('assets/icons/icon-192.png'),
        preg_match('#^uploads/branding/favicon-[a-f0-9]{8}$#', $fav) ? app_url($fav . '-32.png') : app_url('assets/icons/icon-32.png'),
    ];
}

/**
 * Mail menu: new enquiries + support tickets whose latest message is from the student (waiting for a reply).
 * @return array{total:int, items:array<int,array{0:string,1:string,2:string,3:string,4:string,5:string}>} items: [title, line, href, icon, at, kind]
 */
function admin_mail_items(): array
{
    static $out = null;
    if ($out !== null) {
        return $out;
    }
    $out = ['total' => 0, 'items' => []];
    if (can('enquiries.view')) {
        try {
            $out['total'] += (int) db_value("SELECT COUNT(*) FROM enquiries WHERE status = 'new'");
            foreach (db_all("SELECT id, name, subject, message, created_at FROM enquiries WHERE status = 'new' ORDER BY id DESC LIMIT 5") as $r) {
                $line = trim((string) ($r['subject'] ?? '')) !== '' ? (string) $r['subject'] : trim((string) preg_replace('/\s+/', ' ', (string) $r['message']));
                $out['items'][] = [(string) $r['name'], mb_strimwidth($line, 0, 80, '…'), 'enquiries.php?action=edit&id=' . (int) $r['id'], 'inbox', (string) $r['created_at'], 'Enquiry'];
            }
        } catch (PDOException) {
            // table not migrated yet
        }
    }
    if (can('support.manage')) {
        $waiting = "FROM support_tickets t JOIN users u ON u.id = t.user_id
            JOIN support_ticket_messages m ON m.id = (SELECT MAX(x.id) FROM support_ticket_messages x WHERE x.ticket_id = t.id AND x.is_internal = 0)
            WHERE t.status IN ('open','in_progress') AND m.user_id = t.user_id";
        try {
            $out['total'] += (int) db_value("SELECT COUNT(*) {$waiting}");
            foreach (db_all("SELECT t.id, t.subject, u.name, m.created_at {$waiting} ORDER BY m.id DESC LIMIT 5") as $r) {
                $out['items'][] = [(string) $r['name'], 'Ticket #' . (int) $r['id'] . ' · ' . mb_strimwidth((string) $r['subject'], 0, 60, '…'), 'support.php?id=' . (int) $r['id'], 'headset', (string) $r['created_at'], 'Support reply'];
            }
        } catch (PDOException) {
            // table not migrated yet
        }
    }
    usort($out['items'], fn($a, $b) => strcmp($b[4], $a[4]));
    $out['items'] = array_slice($out['items'], 0, 6);
    return $out;
}

/** Auth + permission check, then render <head>, sidebar, top bar, page head and module tabs. Returns the staff user. */
function admin_start(string $title, ?string $permission, string $active, array $opts = []): array
{
    $me = require_staff_page();
    if ($permission) {
        require_permission($permission);
    }
    $GLOBALS['__admin_me'] = $me;
    $roles = user_role_slugs((int) $me['id']);
    $roleName = $roles ? ucwords(str_replace('_', ' ', $roles[0])) : 'Staff';
    $initial = e(mb_strtoupper(mb_substr((string) $me['name'], 0, 1)));
    $avatarUrl = !empty($me['avatar_path']) ? public_file_url((string) $me['avatar_path']) : null;
    $base = admin_url();
    [$logoUrl, $iconUrl, $favUrl] = admin_brand_images();
    $siteName = (string) setting('site_name', 'Laxyam Academy');
    $attention = admin_attention_items();
    $attentionTotal = array_sum(array_column($attention, 3));
    $mail = admin_mail_items();
    $mailAllowed = can('enquiries.view') || can('support.manage');
    $acad = admin_session();
    $acadOptions = admin_session_options();
    // Back to this page after switching the session (explicit date filters are dropped so the new session applies).
    $backQuery = array_diff_key($_GET, array_flip(['from', 'to', 'period', 'range', 'page', 'session']));
    $back = basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php') . ($backQuery ? '?' . http_build_query($backQuery) : '');
    // "+" quick-create menu: only what this staff member may create
    $create = array_filter([
        ['courses.php?action=new', 'New course', 'book', 'courses.manage', 't-purple'],
        ['mock-tests.php?action=new', 'New mock test', 'clipboard', 'exams.manage', 't-indigo'],
        ['users.php?action=new', 'New student', 'users', 'users.manage', 't-green'],
        ['jobs.php?tab=alerts&action=new', 'New job alert', 'megaphone', 'cms.manage', 't-amber'],
        ['blog.php?action=new', 'New blog post', 'edit', 'blog.manage', 't-blue'],
        ['coupons.php?action=new', 'New coupon', 'tag', 'coupons.manage', 't-teal'],
        ['popups.php?action=new', 'New popup', 'expand', 'cms.manage', 't-pink'],
        ['live-classes.php?action=new', 'Schedule live class', 'live', 'live.manage', 't-red'],
    ], fn($c) => can($c[3]));
    $apps = admin_module_links($active);
    $moduleCrumb = admin_breadcrumb_module($active);
    if ($moduleCrumb && (strcasecmp($moduleCrumb[0], $title) === 0 || strcasecmp($moduleCrumb[0], 'Dashboard') === 0)) {
        $moduleCrumb = null; // "Dashboard / Courses", not "Dashboard / Courses / Courses"
    }
    // A screen trail that already starts with the module ("Courses / Edit", "Students / Profile") replaces the module crumb.
    if ($moduleCrumb && isset($opts['crumbs']) && preg_match('~^\s*<a\b[^>]*>(.*?)</a>~is', (string) $opts['crumbs'], $firstCrumb)
        && strcasecmp(trim(html_entity_decode(strip_tags($firstCrumb[1]), ENT_QUOTES)), $moduleCrumb[0]) === 0) {
        $moduleCrumb = null;
    }
    // 'tab_highlight' => false: keep the module tab bar for navigation but mark no screen (access-denied page).
    $moduleTabs = render_module_tabs($active, ($opts['tab_highlight'] ?? true) !== false);
    $GLOBALS['__admin_shell_open'] = true;
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#08264D">
<meta name="color-scheme" content="light dark">
<title><?= e($title) ?> · <?= e($siteName) ?> Admin</title>
<link rel="icon" href="<?= e($favUrl) ?>">
<script>(function(){var d=document.documentElement,t=null;d.classList.add('js');try{t=localStorage.getItem('lx-admin-theme')}catch(e){}if(t!=='dark'&&t!=='light'){t=window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}d.setAttribute('data-theme',t)})();</script>
<link rel="stylesheet" href="<?= e(app_url('assets/admin.css')) ?>?v=<?= (int) @filemtime(APP_ROOT . '/assets/admin.css') ?>">
</head>
<body class="<?= $moduleTabs !== '' ? 'has-modtabs' : '' ?>">
<script>try{if(localStorage.getItem('lx-admin-collapsed')==='1'&&innerWidth>1100)document.body.classList.add('collapsed');if(localStorage.getItem('lx-admin-density')==='compact')document.body.classList.add('compact')}catch(e){}</script>
<a class="skip-link" href="#main">Skip to content</a>
<div class="shell">
  <aside class="sidebar" id="admin-sidebar" aria-label="Admin navigation">
    <div class="sb-head">
      <a class="brand" href="<?= e($base . 'dashboard.php') ?>" aria-label="<?= e($siteName) ?> admin home">
        <span class="brand-logo"><img src="<?= e($logoUrl) ?>" alt="<?= e($siteName) ?>"></span>
        <img class="brand-mark" src="<?= e($iconUrl) ?>" alt="" width="36" height="36">
      </a>
      <button class="sb-close" type="button" data-close-sidebar aria-label="Close menu"><?= icon('close') ?></button>
    </div>
    <?php render_admin_sidebar($active); ?>
  </aside>
  <div class="backdrop" data-close-sidebar></div>
  <div class="main">
    <header class="topbar">
      <button class="tb-btn tb-toggle" type="button" data-toggle-sidebar aria-controls="admin-sidebar" aria-expanded="false" aria-label="Toggle navigation" title="Toggle sidebar"><?= icon('panel') ?></button>
      <form class="search" action="<?= e($base . 'search.php') ?>" role="search" data-suggest="<?= e($base . 'admin-search.php') ?>">
        <?= icon('search') ?>
        <label for="admin-search" class="sr-only">Search the admin</label>
        <input id="admin-search" name="q" type="search" value="<?= e(basename($_SERVER['SCRIPT_NAME'] ?? '') === 'search.php' ? (string) ($_GET['q'] ?? '') : '') ?>" placeholder="Search students, applications, fees, courses…" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="admin-suggest" aria-autocomplete="list" maxlength="100">
        <span class="kbd" aria-hidden="true"><kbd>Ctrl</kbd><kbd>K</kbd></span>
        <button class="search-close" type="button" data-search-close aria-label="Close search"><?= icon('close') ?></button>
        <div class="suggest" id="admin-suggest" role="listbox" aria-label="Search results" hidden></div>
        <span id="admin-search-icons" hidden><?php foreach (['users', 'briefcase', 'cart', 'invoice', 'inbox', 'book', 'clipboard', 'shield', 'grid', 'search'] as $ic): ?><span data-ic="<?= $ic ?>"><?= icon($ic) ?></span><?php endforeach; ?></span>
      </form>
      <button class="tb-btn search-open" type="button" data-search-open aria-label="Search (Ctrl+K)" title="Search"><?= icon('search') ?></button>
      <div class="dropdown tb-session">
        <button class="session-btn" type="button" data-dropdown="session-menu" aria-haspopup="menu" aria-expanded="false" aria-label="Academic session <?= e($acad['label']) ?>. Change session" title="Academic session">
          <?= icon('calendar') ?>
          <span class="sb-txt"><small>Academic Session</small><strong><?= e($acad['label']) ?></strong></span>
          <?= icon('chev', 'i chev') ?>
        </button>
        <div class="menu-pop menu-session" id="session-menu" role="menu" hidden>
          <div class="head"><strong>Academic session</strong><small class="muted">Default date range of the dashboard and reports (1 April – 31 March).</small></div>
          <?php foreach ($acadOptions as $o): $on = $o['key'] === $acad['key']; ?>
          <form method="post" action="<?= e($base . 'profile.php') ?>"><?= csrf_field() ?><input type="hidden" name="_action" value="academic_session"><input type="hidden" name="session" value="<?= e($o['key']) ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
            <button type="submit" role="menuitemradio" aria-checked="<?= $on ? 'true' : 'false' ?>"<?= $on ? ' class="on"' : '' ?>><span class="mi-ic tone-blue"><?= icon('calendar') ?></span><span class="mi-txt"><b><?= e($o['label']) ?></b><small><?= e(academic_session_span($o)) ?><?= $o['current'] ? ' · current' : ($o['upcoming'] ? ' · upcoming (admissions open)' : '') ?></small></span><?= $on ? icon('check', 'i mi-check') : '' ?></button>
          </form>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="spacer"></div>
      <div class="tb-actions">
      <?php if ($create): ?>
      <div class="dropdown">
        <button class="tb-btn tb-plus" type="button" data-dropdown="create-menu" aria-haspopup="menu" aria-expanded="false" aria-label="Create new" title="Create new"><?= icon('plus') ?></button>
        <div class="menu-pop" id="create-menu" role="menu" hidden>
          <div class="head"><strong>Create new</strong></div>
          <?php foreach ($create as [$href, $label, $ico, , $tone]): ?><a role="menuitem" href="<?= e($base . $href) ?>"><span class="mi-ic tile <?= $tone ?>"><?= icon($ico) ?></span><span class="mi-txt"><?= e($label) ?></span></a><?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      <button class="tb-btn tb-theme" type="button" data-theme-toggle aria-pressed="false" aria-label="Dark mode" title="Dark mode"><?= icon('moon', 'i ic-moon') ?><?= icon('sun', 'i ic-sun') ?></button>
      <div class="dropdown">
        <button class="tb-btn" type="button" data-dropdown="attention-menu" aria-haspopup="menu" aria-expanded="false" aria-label="<?= $attentionTotal ? e(plural_n($attentionTotal, 'item') . ' need attention') : 'Notifications' ?>" title="Notifications"><?= icon('bell') ?><?= $attentionTotal ? '<span class="dot">' . ($attentionTotal > 99 ? '99+' : $attentionTotal) . '</span>' : '' ?></button>
        <div class="menu-pop menu-wide" id="attention-menu" role="menu" hidden>
          <div class="head"><strong>Notifications</strong><small class="muted"><?= $attentionTotal ? e(plural_n($attentionTotal, 'item')) . ' waiting for your team' : 'Nothing is waiting for you' ?></small></div>
          <?php if ($attention): foreach ($attention as [$label, $href, $ico, $n, $tone]): ?>
            <a role="menuitem" href="<?= e($base . $href) ?>"><span class="mi-ic tone-<?= e($tone) ?>"><?= icon($ico) ?></span><span class="mi-txt"><?= e($label) ?></span><b class="mi-count"><?= number_format($n) ?></b></a>
          <?php endforeach; else: ?>
            <div class="menu-empty"><?= icon('check-circle') ?> You are all caught up.</div>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($mailAllowed): ?>
      <div class="dropdown">
        <button class="tb-btn" type="button" data-dropdown="mail-menu" aria-haspopup="menu" aria-expanded="false" aria-label="<?= $mail['total'] ? e(plural_n($mail['total'], 'unread message')) : 'Messages' ?>" title="Messages"><?= icon('mail') ?><?= $mail['total'] ? '<span class="dot dot-blue">' . ($mail['total'] > 99 ? '99+' : $mail['total']) . '</span>' : '' ?></button>
        <div class="menu-pop menu-wide menu-mail" id="mail-menu" role="menu" hidden>
          <div class="head"><strong>Messages</strong><small class="muted">New enquiries and support replies</small></div>
          <?php if ($mail['items']): foreach ($mail['items'] as [$who, $line, $href, $ico, $at, $kind]): ?>
            <a role="menuitem" href="<?= e($base . $href) ?>"><span class="mi-av"><?= e(mb_strtoupper(mb_substr($who, 0, 1))) ?></span><span class="mi-txt"><b><?= e($who) ?></b><small><?= e($kind) ?> · <?= e($line) ?></small></span><small class="mi-when"><?= e(fmt_ago($at)) ?></small></a>
          <?php endforeach; else: ?>
            <div class="menu-empty"><?= icon('check-circle') ?> No new messages.</div>
          <?php endif; ?>
          <div class="menu-foot">
            <?php if (can('enquiries.view')): ?><a role="menuitem" href="<?= e($base . 'enquiries.php') ?>">View all enquiries <?= icon('right') ?></a><?php endif; ?>
            <?php if (can('support.manage')): ?><a role="menuitem" href="<?= e($base . 'support.php') ?>">Support inbox <?= icon('right') ?></a><?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <div class="dropdown hide-sm">
        <button class="tb-btn" type="button" data-dropdown="apps-menu" aria-haspopup="menu" aria-expanded="false" aria-label="All modules" title="All modules"><?= icon('grid') ?></button>
        <div class="menu-pop menu-apps" id="apps-menu" role="menu" hidden>
          <div class="head"><strong>All modules</strong><small class="muted">Jump to any part of the admin</small></div>
          <div class="apps-grid">
            <?php foreach ($apps as $m): ?><a role="menuitem" href="<?= e($m['href']) ?>"<?= $m['active'] ? ' class="on" aria-current="page"' : '' ?>><span class="tile <?= e($m['tone']) ?>"><?= icon($m['icon']) ?></span><span><?= e($m['label']) ?></span></a><?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="dropdown">
        <button class="profile-btn" type="button" data-dropdown="profile-menu" aria-haspopup="menu" aria-expanded="false" aria-label="Account menu for <?= e($me['name']) ?>">
          <span class="avatar"><?= $avatarUrl ? '<img src="' . e($avatarUrl) . '" alt="">' : $initial ?></span>
          <span class="who"><strong><?= e($me['name']) ?></strong><small><?= e($roleName) ?></small></span>
          <?= icon('chev', 'i chev') ?>
        </button>
        <div class="menu-pop" id="profile-menu" role="menu" hidden>
          <div class="head head-user"><span class="avatar"><?= $avatarUrl ? '<img src="' . e($avatarUrl) . '" alt="">' : $initial ?></span><span class="mi-txt"><strong><?= e($me['name']) ?></strong><small class="muted"><?= e($me['email']) ?></small></span></div>
          <a role="menuitem" href="<?= e($base . 'profile.php') ?>"><?= icon('user') ?> My profile</a>
          <a role="menuitem" href="<?= e($base . 'two-factor.php') ?>"><?= icon('shield') ?> Two-factor authentication</a>
          <a role="menuitem" href="<?= e(app_url('')) ?>" target="_blank" rel="noopener"><?= icon('external') ?> View website</a>
          <form method="post" action="<?= e($base . 'login.php?logout=1') ?>"><?= csrf_field() ?><button role="menuitem" class="danger"><?= icon('logout') ?> Logout</button></form>
        </div>
      </div>
      </div>
    </header>
    <main class="content" id="main" tabindex="-1">
      <?php if (!empty($opts['head'])): ?>
      <div class="page-head">
        <div class="ph-main">
          <nav class="crumbs" aria-label="Breadcrumb"><a href="<?= e($base . 'dashboard.php') ?>">Dashboard</a><?= $moduleCrumb ? '<span class="sep" aria-hidden="true">/</span><a href="' . e($moduleCrumb[1]) . '">' . e($moduleCrumb[0]) . '</a>' : '' ?><span class="sep" aria-hidden="true">/</span><span aria-current="page"><?= $opts['crumbs'] ?? e($title) ?></span></nav>
          <h1><?= e($title) ?></h1>
          <?php if (!empty($opts['subtitle'])): ?><p><?= e($opts['subtitle']) ?></p><?php endif; ?>
        </div>
        <?php if (!empty($opts['actions'])): ?><div class="ph-actions"><?= $opts['actions'] ?></div><?php endif; ?>
      </div>
      <?php endif; ?>
      <?= $moduleTabs ?>
      <?php render_flash(); ?>
<?php
    return $me;
}

function admin_end(): void
{
    $GLOBALS['__admin_shell_open'] = false;
    ?>
    </main>
  </div>
</div>
<div class="toasts" id="toasts" role="region" aria-live="polite" aria-label="Notifications"></div>
<dialog class="modal" id="confirm-dialog" aria-labelledby="confirm-title" aria-describedby="confirm-text">
  <div class="body"><span class="modal-ic"><?= icon('alert') ?></span><div><h2 id="confirm-title">Please confirm</h2><p id="confirm-text" class="muted"></p></div></div>
  <div class="foot"><button type="button" class="btn btn-light" value="cancel">Cancel</button><button type="button" class="btn btn-primary" value="ok">Confirm</button></div>
</dialog>
<script src="<?= e(app_url('assets/admin.js')) ?>?v=<?= (int) @filemtime(APP_ROOT . '/assets/admin.js') ?>"></script>
</body>
</html>
<?php
}

/**
 * Shared shell for the staff sign-in, forgot-password and reset-password screens: brand panel + form card.
 * Call admin_auth_open(), print the card content (usually a <form>), then admin_auth_close().
 */
function admin_auth_open(string $pageTitle): void
{
    [$logoUrl, , $favUrl] = admin_brand_images();
    $siteName = (string) setting('site_name', 'Laxyam Academy');
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#08264D">
<meta name="color-scheme" content="light dark">
<title><?= e($pageTitle) ?> · <?= e($siteName) ?></title>
<link rel="icon" href="<?= e($favUrl) ?>">
<script>(function(){var d=document.documentElement,t=null;d.classList.add('js');try{t=localStorage.getItem('lx-admin-theme')}catch(e){}if(t!=='dark'&&t!=='light'){t=window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}d.setAttribute('data-theme',t)})();</script>
<link rel="stylesheet" href="<?= e(app_url('assets/admin.css')) ?>?v=<?= (int) @filemtime(APP_ROOT . '/assets/admin.css') ?>">
</head>
<body class="auth-body">
<div class="login">
  <aside class="login-brand" aria-label="<?= e($siteName) ?>">
    <div class="login-brand-top">
      <span class="login-logo"><img src="<?= e($logoUrl) ?>" alt="<?= e($siteName) ?>"></span>
      <span class="login-tag"><?= icon('shield') ?> Staff portal</span>
    </div>
    <div class="login-pitch">
      <h2>Run your academy<br>from <span>one calm place.</span></h2>
      <p>Courses, mock tests, enrolments, fees, enquiries and the website - managed securely by your team.</p>
      <ul class="login-points">
        <li><span><?= icon('shield') ?></span><div><strong>Role-based access</strong><small>Every screen checks the staff member's permissions, with two-step verification.</small></div></li>
        <li><span><?= icon('chart') ?></span><div><strong>Live analytics</strong><small>Revenue, enrolments and test results for any academic session.</small></div></li>
        <li><span><?= icon('clipboard') ?></span><div><strong>Exams made simple</strong><small>Mock tests, answer keys and results in minutes.</small></div></li>
      </ul>
    </div>
    <p class="login-foot">© <?= date('Y') ?> <?= e($siteName) ?> · Staff access only. Sign-ins are recorded.</p>
  </aside>
  <main class="login-main">
    <div class="card login-card">
      <span class="login-mobile-logo"><img src="<?= e($logoUrl) ?>" alt="<?= e($siteName) ?>"></span>
<?php
}

function admin_auth_close(): void
{
    ?>
    </div>
    <p class="login-legal"><a href="<?= e(app_url('')) ?>"><?= icon('left') ?> Back to the website</a></p>
  </main>
</div>
<script>
// Show / hide passwords (the fields stay password fields for password managers) + busy state on submit
(function () {
  document.querySelectorAll('[data-pw-toggle]').forEach(function (b) {
    var i = document.getElementById(b.getAttribute('aria-controls'));
    if (!i) return;
    b.addEventListener('click', function () {
      var show = i.type === 'password';
      i.type = show ? 'text' : 'password';
      b.textContent = show ? 'Hide' : 'Show';
      b.setAttribute('aria-pressed', String(show));
      i.focus();
    });
  });
  document.querySelectorAll('form').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      var btn = e.submitter || f.querySelector('button:not([type=button])');
      if (btn) { setTimeout(function () { btn.disabled = true; btn.classList.add('loading'); }, 0); }
    });
  });
})();
</script>
</body>
</html>
<?php
}

/** Styled access-denied screen (used by require_permission() for admin pages). */
function admin_forbidden(string $permission): never
{
    if (!headers_sent()) {
        http_response_code(403);
    }
    $body = '<section class="forbidden card" role="alert"><span class="fb-ic">' . icon('lock') . '</span>'
        . '<h1>You don’t have access to this screen</h1>'
        . '<p class="muted">Your role does not include the <code>' . e($permission) . '</code> permission. Ask a Super Admin to add it to your role if you need this screen.</p>'
        . '<div class="fb-actions"><a class="btn btn-primary" href="' . e(admin_url('dashboard.php')) . '">' . icon('home') . ' Go to dashboard</a>'
        . '<a class="btn btn-light" href="' . e(admin_url('profile.php')) . '">' . icon('user') . ' My profile</a></div></section>';
    if (!empty($GLOBALS['__admin_shell_open'])) {
        echo $body; // the page already started: finish the shell around the message
    } else {
        admin_start('Access denied', null, '', ['tab_highlight' => false]);
        echo $body;
    }
    admin_end();
    exit;
}
