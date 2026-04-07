<?php

require_once __DIR__ . '/bootstrap.php';

function officefb_topic_ui_meta($topic)
{
    $map = [
        'การให้คำแนะนำและข้อมูล' => ['label' => 'การให้คำแนะนำและข้อมูล', 'icon' => 'bi-chat-dots-fill', 'tone' => 'topic-tone-guidance'],
        'ความรวดเร็วในการให้บริการ' => ['label' => 'ความรวดเร็วในการให้บริการ', 'icon' => 'bi-lightning-charge-fill', 'tone' => 'topic-tone-speed'],
        'ความสุภาพและการต้อนรับ' => ['label' => 'ความสุภาพและการต้อนรับ', 'icon' => 'bi-emoji-smile-fill', 'tone' => 'topic-tone-welcome'],
        'ความชัดเจนของขั้นตอนและเอกสาร' => ['label' => 'ความชัดเจนของขั้นตอนและเอกสาร', 'icon' => 'bi-file-earmark-text-fill', 'tone' => 'topic-tone-docs'],
        'การติดตามงานและการประสานงาน' => ['label' => 'การติดตามงานและการประสานงาน', 'icon' => 'bi-diagram-3-fill', 'tone' => 'topic-tone-followup'],
    ];

    return isset($map[$topic]) ? $map[$topic] : ['label' => $topic, 'icon' => 'bi-tag-fill', 'tone' => 'topic-tone-default'];
}

$state = officefb_bootstrap_state();
$pdo = $state['pdo'];
$db_ready = $state['ok'] && officefb_table_exists($pdo, 'officefb_staff') && officefb_table_exists($pdo, 'officefb_ratings');

$staff_list = [];
$topics = [];
$error_message = $state['error'];
$submit_endpoint = officefb_path('kiosk.submit');

if ($db_ready) {
    try {
        $stmtStaff = $pdo->query("
            SELECT id, full_name, position_name, photo_url, department_name, service_area
            FROM officefb_staff
            WHERE is_active = 1
            ORDER BY display_order ASC, id ASC
        ");
        $staff_list = $stmtStaff->fetchAll(PDO::FETCH_ASSOC);

        if (officefb_table_exists($pdo, 'officefb_topics')) {
            $stmtTopics = $pdo->query("
                SELECT topic_name
                FROM officefb_topics
                WHERE is_active = 1
                ORDER BY display_order ASC, id ASC
            ");
            $topics = $stmtTopics->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (Throwable $e) {
        $db_ready = false;
        $error_message = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Office Service Feedback Kiosk</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --bg-top: #2b1b0d;
            --bg-bottom: #f4e8d4;
            --panel: rgba(255, 248, 240, 0.92);
            --panel-border: rgba(90, 58, 25, 0.12);
            --text-main: #2e241b;
            --text-soft: #766354;
            --accent: #9d6b2f;
            --accent-strong: #6a3f14;
            --excellent: #2d6a4f;
            --good: #40916c;
            --poor: #f4a261;
            --very-poor: #d62828;
        }

        html, body {
            min-height: 100%;
            margin: 0;
            font-family: "Sarabun", system-ui, sans-serif;
            background:
                radial-gradient(circle at top right, rgba(255, 219, 167, 0.4), transparent 30%),
                linear-gradient(180deg, var(--bg-top) 0%, #5d3d1f 22%, var(--bg-bottom) 100%);
            color: var(--text-main);
        }

        body {
            padding: 1.25rem;
        }

        .shell {
            max-width: 1380px;
            margin: 0 auto;
        }

        .hero-panel,
        .content-panel,
        .feedback-overlay-card,
        .thankyou-card {
            border-radius: 32px;
            background: var(--panel);
            backdrop-filter: blur(14px);
            border: 1px solid var(--panel-border);
            box-shadow: 0 25px 80px rgba(53, 31, 14, 0.18);
        }

        .hero-panel {
            padding: .9rem 1.2rem;
            color: #fff8f0;
            background:
                linear-gradient(140deg, rgba(63, 39, 16, 0.93), rgba(140, 92, 37, 0.83)),
                rgba(255, 248, 240, 0.1);
            border-color: rgba(255, 230, 194, 0.14);
        }

        .hero-title {
            font-size: clamp(1.5rem, 2.6vw, 2.4rem);
            font-weight: 800;
            margin: 0;
            letter-spacing: -0.03em;
            line-height: 1.05;
        }

        .hero-subtitle {
            font-size: clamp(.92rem, 1.2vw, 1.1rem);
            margin: .2rem 0 0;
            opacity: .88;
        }

        .meta-pill {
            background: rgba(255, 244, 229, 0.12);
            border: 1px solid rgba(255, 235, 210, 0.18);
            color: #fff4e8;
            border-radius: 999px;
            padding: .42rem .85rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            font-size: .95rem;
        }

        .content-panel {
            margin-top: 1.1rem;
            padding: 1.25rem;
        }

        .staff-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1rem;
        }

        .staff-card {
            border: 0;
            background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(249, 241, 231, 0.96));
            border-radius: 28px;
            padding: 1rem;
            box-shadow: 0 16px 32px rgba(59, 34, 17, 0.12);
            transition: transform .2s ease, box-shadow .2s ease;
            height: 100%;
            text-align: left;
        }

        .staff-card:hover,
        .staff-card:focus-visible {
            transform: translateY(-6px) scale(1.01);
            box-shadow: 0 24px 42px rgba(59, 34, 17, 0.18);
        }

        .staff-photo {
            width: 100%;
            aspect-ratio: 4 / 4.8;
            object-fit: cover;
            border-radius: 22px;
            background: linear-gradient(135deg, #f0e2d0, #d7b58a);
        }

        .staff-name {
            font-size: 1.35rem;
            font-weight: 800;
            margin-top: .85rem;
            margin-bottom: .2rem;
        }

        .staff-role {
            font-size: 1rem;
            color: var(--text-soft);
            margin-bottom: .6rem;
        }

        .staff-badge {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            font-size: .9rem;
            padding: .4rem .7rem;
            border-radius: 999px;
            background: rgba(157, 107, 47, 0.12);
            color: var(--accent-strong);
            font-weight: 700;
        }

        .overlay-screen {
            position: fixed;
            inset: 0;
            background: rgba(34, 20, 11, 0.58);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            z-index: 1000;
        }

        .overlay-screen.is-visible {
            display: flex;
        }

        .feedback-overlay-card,
        .thankyou-card {
            width: min(1200px, 96vw);
            padding: 1.4rem;
        }
        .feedback-overlay-card {
            max-height: calc(100vh - 2rem);
            overflow: auto;
        }
        .overlay-header {
            position: sticky;
            top: 0;
            z-index: 2;
            background: linear-gradient(180deg, rgba(255, 248, 240, 0.98), rgba(255, 248, 240, 0.92));
            border-bottom: 1px solid rgba(90, 58, 25, 0.08);
            margin: -1.4rem -1.4rem 1rem;
            padding: 1rem 1.4rem .85rem;
            border-radius: 32px 32px 20px 20px;
        }

        .selected-staff {
            display: flex;
            align-items: center;
            gap: .85rem;
            margin-bottom: 1rem;
            padding: .7rem .85rem;
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.7);
            border: 1px solid rgba(90, 58, 25, 0.08);
        }

        .selected-staff img {
            width: 92px;
            height: 112px;
            border-radius: 18px;
            object-fit: cover;
            background: linear-gradient(135deg, #f0e2d0, #d7b58a);
        }
        .selected-staff .staff-context {
            min-width: 0;
        }
        .selected-staff .staff-context-label {
            font-size: .76rem;
            letter-spacing: .04em;
            margin-bottom: .2rem;
        }
        .selected-staff .staff-context-name {
            font-size: clamp(1.35rem, 2.5vw, 2rem);
            line-height: 1.02;
            margin-bottom: .2rem;
        }
        .selected-staff .staff-context-role {
            font-size: 1.05rem;
            line-height: 1.2;
        }

        .rating-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
        }

        .rating-button {
            border: 0;
            border-radius: 28px;
            color: #fff;
            min-height: 260px;
            font-size: 1.35rem;
            font-weight: 800;
            box-shadow: 0 22px 36px rgba(0, 0, 0, 0.15);
            transition: transform .18s ease, box-shadow .18s ease, opacity .18s ease;
            padding: 1rem;
        }

        .rating-button:hover,
        .rating-button:focus-visible {
            transform: translateY(-6px);
            box-shadow: 0 28px 46px rgba(0, 0, 0, 0.2);
        }

        .rating-button .emoji {
            display: block;
            font-size: 4rem;
            margin-bottom: .8rem;
        }

        .rating-button .small-label {
            display: block;
            margin-top: .45rem;
            font-size: 1rem;
            opacity: .86;
            font-weight: 600;
        }

        .rating-excellent { background: linear-gradient(180deg, #2d6a4f, #1f4c39); }
        .rating-good { background: linear-gradient(180deg, #40916c, #2d6a4f); }
        .rating-poor { background: linear-gradient(180deg, #f4a261, #d9822b); }
        .rating-very-poor { background: linear-gradient(180deg, #d62828, #9b1d1d); }

        .optional-panel {
            margin-top: 1rem;
            border-radius: 24px;
            background: rgba(255,255,255,0.72);
            padding: 1rem;
            border: 1px solid rgba(82, 51, 26, 0.08);
        }

        .topic-chip {
            border-radius: 20px;
            border: 1px solid rgba(106, 63, 20, 0.16);
            background: white;
            color: var(--accent-strong);
            padding: .85rem 1rem;
            font-weight: 700;
            width: 100%;
            min-height: 64px;
            text-align: center;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: .32rem;
            line-height: 1.2;
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        }
        .topic-chip-icon {
            font-size: 1.2rem;
            line-height: 1;
            width: 2.1rem;
            height: 2.1rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(106, 63, 20, 0.08);
        }
        .topic-chip-label {
            display: block;
            font-size: .88rem;
            line-height: 1.3;
            text-wrap: balance;
        }
        .topic-tone-guidance .topic-chip-icon {
            color: #8b5e34;
            background: rgba(139, 94, 52, 0.12);
        }
        .topic-tone-speed .topic-chip-icon {
            color: #c58a00;
            background: rgba(255, 199, 0, 0.16);
        }
        .topic-tone-welcome .topic-chip-icon {
            color: #2d8a57;
            background: rgba(45, 138, 87, 0.14);
        }
        .topic-tone-docs .topic-chip-icon {
            color: #2d6cdf;
            background: rgba(45, 108, 223, 0.12);
        }
        .topic-tone-followup .topic-chip-icon {
            color: #7b4db7;
            background: rgba(123, 77, 183, 0.12);
        }
        .topic-tone-default .topic-chip-icon {
            color: var(--accent-strong);
            background: rgba(106, 63, 20, 0.08);
        }

        .topic-chip.active {
            background: rgba(157, 107, 47, 0.18);
            border-color: rgba(106, 63, 20, 0.45);
            box-shadow: 0 12px 24px rgba(59, 34, 17, 0.08);
        }

        .topic-chip:hover,
        .topic-chip:focus-visible {
            transform: translateY(-2px);
            box-shadow: 0 10px 18px rgba(59, 34, 17, 0.08);
        }

        .topic-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: .7rem;
        }

        .thankyou-card {
            text-align: center;
            background:
                linear-gradient(180deg, rgba(255,255,255,0.95), rgba(253, 248, 241, 0.98)),
                white;
        }

        .thankyou-landmark {
            font-size: clamp(3rem, 8vw, 8rem);
            color: rgba(157, 107, 47, 0.18);
            line-height: 1;
            margin-bottom: .5rem;
        }

        .countdown-badge {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .55rem .9rem;
            border-radius: 999px;
            background: rgba(45, 106, 79, 0.12);
            color: var(--excellent);
            font-weight: 800;
        }

        .empty-state {
            border-radius: 28px;
            background: rgba(255,255,255,0.78);
            padding: 2rem;
            text-align: center;
            color: var(--text-soft);
        }

        @media (max-width: 991px) {
            .rating-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .topic-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .selected-staff {
                flex-direction: column;
                align-items: flex-start;
            }

            .selected-staff img {
                width: 110px;
                height: 132px;
            }
        }

        @media (max-width: 640px) {
            body {
                padding: .8rem;
            }

            .rating-grid {
                grid-template-columns: 1fr;
            }

            .topic-grid {
                grid-template-columns: 1fr;
            }

            .rating-button {
                min-height: 160px;
            }
        }

        @media (max-height: 820px) {
            .hero-subtitle {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="shell">
        <div class="hero-panel d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3">
            <div>
                <div class="meta-pill mb-2"><i class="bi bi-tablet-landscape"></i> Office Feedback Kiosk</div>
                <h1 class="hero-title">โปรดประเมินการให้บริการของสำนักงานคณะ</h1>
                <p class="hero-subtitle">แตะรูปเจ้าหน้าที่ แล้วกดคะแนนได้ทันที ใช้เวลาไม่ถึง 5 วินาที</p>
            </div>
            <div class="meta-pill">
                <i class="bi bi-clock-history"></i>
                <span id="clockText">--:--</span>
            </div>
        </div>

        <div class="content-panel">
            <?php if (!$db_ready): ?>
                <div class="empty-state">
                    <div class="fs-1 mb-3"><i class="bi bi-exclamation-diamond"></i></div>
                    <h2 class="fw-bold mb-2">ระบบยังไม่พร้อมใช้งาน</h2>
                    <p class="mb-2">ระบบพยายามเตรียมตาราง `officefb_*` อัตโนมัติแล้ว แต่ยังไม่สำเร็จ กรุณาตรวจสอบ `config.php` และสิทธิ์ฐานข้อมูล</p>
                    <?php if ($error_message !== ''): ?>
                        <code><?= htmlspecialchars($error_message) ?></code>
                    <?php endif; ?>
                </div>
            <?php elseif (empty($staff_list)): ?>
                <div class="empty-state">
                    <div class="fs-1 mb-3"><i class="bi bi-people"></i></div>
                    <h2 class="fw-bold mb-2">ยังไม่มีรายชื่อเจ้าหน้าที่</h2>
                    <p class="mb-0">เข้าสู่หน้า admin เพื่อเติมหรืออัปเดตรายชื่อเจ้าหน้าที่ของสำนักงานคณะได้ทันที</p>
                </div>
            <?php else: ?>
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <h2 class="fw-bold mb-1">เลือกเจ้าหน้าที่ที่ให้บริการคุณ</h2>
                        <div class="text-secondary">แตะที่รูป 1 ครั้ง จากนั้นกดระดับความพึงพอใจจากปุ่มขนาดใหญ่</div>
                    </div>
                    <div class="meta-pill" id="cooldownBadge" style="display:none;">
                        <i class="bi bi-hourglass-split"></i>
                        <span id="cooldownText">รอสักครู่...</span>
                    </div>
                </div>

                <div class="staff-grid">
                    <?php foreach ($staff_list as $staff): ?>
                        <?php
                        $photo_url = trim((string) $staff['photo_url']);
                        if ($photo_url === '') {
                            $photo_url = 'https://placehold.co/600x720/f0e2d0/6a3f14?text=Office+Staff';
                        }
                        ?>
                        <button
                            type="button"
                            class="staff-card"
                            data-staff-id="<?= (int) $staff['id'] ?>"
                            data-staff-name="<?= htmlspecialchars($staff['full_name'], ENT_QUOTES) ?>"
                            data-staff-role="<?= htmlspecialchars($staff['position_name'], ENT_QUOTES) ?>"
                            data-staff-photo="<?= htmlspecialchars($photo_url, ENT_QUOTES) ?>"
                        >
                            <img
                                class="staff-photo"
                                src="<?= htmlspecialchars($photo_url) ?>"
                                alt="<?= htmlspecialchars($staff['full_name']) ?>"
                                onerror="this.onerror=null;this.src='https://placehold.co/600x720/f0e2d0/6a3f14?text=Office+Staff';"
                            >
                            <div class="staff-name"><?= htmlspecialchars($staff['full_name']) ?></div>
                            <div class="staff-role"><?= htmlspecialchars($staff['position_name']) ?></div>
                            <span class="staff-badge"><i class="bi bi-hand-index-thumb"></i> แตะเพื่อให้คะแนน</span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="overlay-screen" id="feedbackOverlay">
        <div class="feedback-overlay-card">
            <div class="overlay-header">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <h2 class="fw-bold mb-1">โปรดเลือกคะแนนความพึงพอใจ</h2>
                        <div class="text-secondary">กดคะแนนได้ทันที หรือเปิดเหตุผลเพิ่มเติมด้านล่างถ้าต้องการ</div>
                    </div>
                    <button class="btn btn-outline-secondary btn-lg rounded-pill" type="button" id="closeOverlayBtn">
                        <i class="bi bi-x-lg"></i> ย้อนกลับ
                    </button>
                </div>
            </div>

            <div class="selected-staff">
                <img src="" alt="" id="selectedStaffPhoto">
                <div class="staff-context">
                    <div class="text-secondary text-uppercase fw-bold staff-context-label">กำลังประเมิน</div>
                    <h3 class="fw-bold staff-context-name" id="selectedStaffName">-</h3>
                    <div class="text-secondary staff-context-role" id="selectedStaffRole">-</div>
                </div>
            </div>

            <div class="rating-grid">
                <button class="rating-button rating-excellent" type="button" data-score="4">
                    <span class="emoji">🤩</span>
                    Excellent
                    <span class="small-label">ยอดเยี่ยมมาก</span>
                </button>
                <button class="rating-button rating-good" type="button" data-score="3">
                    <span class="emoji">🙂</span>
                    Good
                    <span class="small-label">ดี</span>
                </button>
                <button class="rating-button rating-poor" type="button" data-score="2">
                    <span class="emoji">😐</span>
                    Poor
                    <span class="small-label">ควรปรับปรุง</span>
                </button>
                <button class="rating-button rating-very-poor" type="button" data-score="1">
                    <span class="emoji">🙁</span>
                    Very Poor
                    <span class="small-label">ไม่พึงพอใจ</span>
                </button>
            </div>

            <div class="optional-panel">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                    <div>
                        <div class="fw-bold">เหตุผลเพิ่มเติม (ไม่บังคับ)</div>
                        <div class="text-secondary small">เลือกหมวดบริการหรือพิมพ์คำแนะนำสั้น ๆ ได้ ถ้าไม่กรอกก็สามารถกดคะแนนได้ทันที</div>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary rounded-pill" type="button" id="clearOptionalBtn">
                        ล้างเหตุผลเพิ่มเติม
                    </button>
                </div>

                <div class="topic-grid mb-2">
                    <?php foreach ($topics as $topic): ?>
                        <?php $topic_meta = officefb_topic_ui_meta($topic); ?>
                        <button
                            type="button"
                            class="topic-chip <?= htmlspecialchars($topic_meta['tone']) ?>"
                            data-topic="<?= htmlspecialchars($topic, ENT_QUOTES) ?>"
                            title="<?= htmlspecialchars($topic) ?>"
                        >
                            <span class="topic-chip-icon" aria-hidden="true">
                                <i class="bi <?= htmlspecialchars($topic_meta['icon']) ?>"></i>
                            </span>
                            <span class="topic-chip-label"><?= htmlspecialchars($topic_meta['label']) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="small text-secondary mb-3" id="selectedTopicHint">แตะหัวข้อบริการด้านบนได้ หากต้องการระบุเหตุผลเพิ่มเติมให้ชัดขึ้น</div>

                <textarea class="form-control form-control-lg rounded-4" rows="3" id="commentText" placeholder="เช่น ให้คำแนะนำดีมาก, อยากให้ตอบไวขึ้น, ให้บริการสุภาพมาก"></textarea>
            </div>
        </div>
    </div>

    <div class="overlay-screen" id="thankYouOverlay">
        <div class="thankyou-card">
            <div class="thankyou-landmark">🏙️</div>
            <div class="meta-pill mb-3"><i class="bi bi-check2-circle"></i> Thank you for your feedback</div>
            <h2 class="fw-bold mb-2" id="thankYouTitle">ขอบคุณสำหรับการประเมิน</h2>
            <p class="fs-5 text-secondary mb-4">ระบบได้รับคำตอบของคุณเรียบร้อยแล้ว ขอให้มีวันที่ดี</p>
            <div class="countdown-badge">
                <i class="bi bi-arrow-clockwise"></i>
                <span>ระบบจะกลับหน้าแรกใน <span id="countdownNumber">4</span> วินาที</span>
            </div>
        </div>
    </div>

    <script>
        const staffButtons = document.querySelectorAll('.staff-card');
        const feedbackOverlay = document.getElementById('feedbackOverlay');
        const thankYouOverlay = document.getElementById('thankYouOverlay');
        const selectedStaffName = document.getElementById('selectedStaffName');
        const selectedStaffRole = document.getElementById('selectedStaffRole');
        const selectedStaffPhoto = document.getElementById('selectedStaffPhoto');
        const closeOverlayBtn = document.getElementById('closeOverlayBtn');
        const clearOptionalBtn = document.getElementById('clearOptionalBtn');
        const topicChips = document.querySelectorAll('.topic-chip');
        const selectedTopicHint = document.getElementById('selectedTopicHint');
        const commentText = document.getElementById('commentText');
        const cooldownBadge = document.getElementById('cooldownBadge');
        const cooldownText = document.getElementById('cooldownText');
        const clockText = document.getElementById('clockText');
        const countdownNumber = document.getElementById('countdownNumber');
        const ratingButtons = document.querySelectorAll('.rating-button');
        const submitEndpoint = <?= json_encode($submit_endpoint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        let selectedStaff = null;
        let selectedTopic = '';
        let isSubmitting = false;
        let thankyouTimer = null;
        let countdownTimer = null;

        function updateClock() {
            const now = new Date();
            clockText.textContent = now.toLocaleString('th-TH', {
                hour: '2-digit',
                minute: '2-digit',
                weekday: 'short',
                day: '2-digit',
                month: 'short'
            });
        }

        function getDeviceToken() {
            const key = 'officefb_device_token';
            let token = localStorage.getItem(key);
            if (!token) {
                token = 'tablet-' + Math.random().toString(36).slice(2, 12);
                localStorage.setItem(key, token);
            }
            return token;
        }

        function getCooldownRemaining() {
            const until = parseInt(localStorage.getItem('officefb_cooldown_until') || '0', 10);
            return Math.max(0, Math.ceil((until - Date.now()) / 1000));
        }

        function startCooldown(seconds) {
            const until = Date.now() + (seconds * 1000);
            localStorage.setItem('officefb_cooldown_until', String(until));
            renderCooldown();
        }

        function updateTopicHint(text) {
            if (selectedTopicHint) {
                selectedTopicHint.textContent = text;
            }
        }

        function renderCooldown() {
            const remaining = getCooldownRemaining();
            if (remaining > 0) {
                cooldownBadge.style.display = 'inline-flex';
                cooldownText.textContent = `อุปกรณ์นี้พัก ${remaining} วินาที ก่อนประเมินครั้งถัดไป`;
                ratingButtons.forEach(btn => btn.disabled = true);
            } else {
                cooldownBadge.style.display = 'none';
                ratingButtons.forEach(btn => btn.disabled = false);
            }
        }

        function resetOptionalFields() {
            selectedTopic = '';
            commentText.value = '';
            topicChips.forEach(chip => chip.classList.remove('active'));
            updateTopicHint('แตะหัวข้อสั้น ๆ ด้านบนได้ หากต้องการระบุเหตุผลให้ชัดขึ้น ระบบจะเก็บชื่อเต็มให้โดยอัตโนมัติ');
        }

        function openRatingOverlay(button) {
            if (getCooldownRemaining() > 0) {
                renderCooldown();
                return;
            }

            selectedStaff = {
                id: button.getAttribute('data-staff-id'),
                name: button.getAttribute('data-staff-name'),
                role: button.getAttribute('data-staff-role'),
                photo: button.getAttribute('data-staff-photo')
            };

            selectedStaffName.textContent = selectedStaff.name || '-';
            selectedStaffRole.textContent = selectedStaff.role || '-';
            selectedStaffPhoto.onerror = function () {
                this.onerror = null;
                this.src = 'https://placehold.co/600x720/f0e2d0/6a3f14?text=Office+Staff';
            };
            selectedStaffPhoto.src = selectedStaff.photo || 'https://placehold.co/600x720/f0e2d0/6a3f14?text=Office+Staff';
            selectedStaffPhoto.alt = selectedStaff.name || '';
            resetOptionalFields();
            feedbackOverlay.classList.add('is-visible');
            renderCooldown();
        }

        function closeRatingOverlay() {
            feedbackOverlay.classList.remove('is-visible');
            selectedStaff = null;
            resetOptionalFields();
        }

        async function submitRating(score) {
            if (!selectedStaff || isSubmitting || getCooldownRemaining() > 0) {
                renderCooldown();
                return;
            }

            isSubmitting = true;
            ratingButtons.forEach(btn => btn.style.opacity = '0.72');

            const payload = new URLSearchParams();
            payload.append('staff_id', selectedStaff.id);
            payload.append('rating_score', score);
            payload.append('service_topic', selectedTopic);
            payload.append('comment_text', commentText.value.trim());
            payload.append('device_token', getDeviceToken());
            payload.append('device_name', 'Office Feedback Kiosk');

            try {
                const response = await fetch(submitEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                    },
                    body: payload.toString()
                });

                const result = await response.json();
                if (!result.success) {
                    if (result.cooldown_remaining) {
                        startCooldown(result.cooldown_remaining);
                    }
                    alert(result.error || 'เกิดข้อผิดพลาดในการบันทึกแบบประเมิน');
                    renderCooldown();
                    return;
                }

                startCooldown(5);
                showThankYou(result.meta ? result.meta.thai : '');
            } catch (error) {
                alert('ไม่สามารถเชื่อมต่อระบบบันทึกแบบประเมินได้');
            } finally {
                isSubmitting = false;
                ratingButtons.forEach(btn => btn.style.opacity = '1');
            }
        }

        function showThankYou(thaiLabel) {
            closeRatingOverlay();
            const title = document.getElementById('thankYouTitle');
            title.textContent = thaiLabel !== '' ? `ขอบคุณสำหรับคะแนนระดับ "${thaiLabel}"` : 'ขอบคุณสำหรับการประเมิน';
            thankYouOverlay.classList.add('is-visible');

            let remaining = 4;
            countdownNumber.textContent = remaining;

            clearTimeout(thankyouTimer);
            clearInterval(countdownTimer);

            countdownTimer = setInterval(() => {
                remaining -= 1;
                if (remaining < 0) {
                    remaining = 0;
                }
                countdownNumber.textContent = remaining;
            }, 1000);

            thankyouTimer = setTimeout(() => {
                clearInterval(countdownTimer);
                thankYouOverlay.classList.remove('is-visible');
                renderCooldown();
            }, 4000);
        }

        staffButtons.forEach(button => {
            button.addEventListener('click', () => openRatingOverlay(button));
        });

        ratingButtons.forEach(button => {
            button.addEventListener('click', () => submitRating(button.getAttribute('data-score')));
        });

        topicChips.forEach(button => {
            button.addEventListener('click', () => {
                if (selectedTopic === button.getAttribute('data-topic')) {
                    selectedTopic = '';
                    button.classList.remove('active');
                    updateTopicHint('แตะหัวข้อสั้น ๆ ด้านบนได้ หากต้องการระบุเหตุผลให้ชัดขึ้น ระบบจะเก็บชื่อเต็มให้โดยอัตโนมัติ');
                    return;
                }

                selectedTopic = button.getAttribute('data-topic');
                topicChips.forEach(chip => chip.classList.remove('active'));
                button.classList.add('active');
                updateTopicHint('หัวข้อที่เลือก: ' + selectedTopic);
            });
        });

        if (closeOverlayBtn) {
            closeOverlayBtn.addEventListener('click', closeRatingOverlay);
        }

        if (feedbackOverlay) {
            feedbackOverlay.addEventListener('click', (event) => {
                if (event.target === feedbackOverlay) {
                    closeRatingOverlay();
                }
            });
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && feedbackOverlay && feedbackOverlay.classList.contains('is-visible')) {
                closeRatingOverlay();
            }
        });

        if (clearOptionalBtn) {
            clearOptionalBtn.addEventListener('click', resetOptionalFields);
        }

        setInterval(updateClock, 1000);
        updateClock();
        setInterval(renderCooldown, 1000);
        renderCooldown();
    </script>
</body>
</html>
