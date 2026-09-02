<?php
require_once 'includes/functions.php';
require_once 'includes/lang.php';
$page_title = __('pres_page_title');
require_once 'includes/header.php';
?>
<style>
/* ── Presentation Page Styles ── */
.pres-hero {
    background: linear-gradient(135deg, #1b5e20 0%, #2e7d32 50%, #388e3c 100%);
    color: #fff;
    padding: 60px 40px;
    border-radius: 12px;
    margin-bottom: 40px;
    position: relative;
    overflow: hidden;
}
.pres-hero::before {
    content: '';
    position: absolute; top: -60px; right: -60px;
    width: 260px; height: 260px;
    border-radius: 50%;
    background: rgba(255,255,255,0.07);
}
.pres-hero::after {
    content: '';
    position: absolute; bottom: -80px; left: -40px;
    width: 200px; height: 200px;
    border-radius: 50%;
    background: rgba(255,255,255,0.05);
}
.pres-hero h1 { font-size: 2.6rem; font-weight: 800; margin-bottom: 10px; }
.pres-hero p  { font-size: 1.15rem; opacity: .88; max-width: 640px; }
.pres-hero .badge-pill {
    display: inline-block;
    background: rgba(255,255,255,0.18);
    border: 1px solid rgba(255,255,255,0.35);
    border-radius: 30px;
    padding: 4px 16px;
    font-size: 0.82rem;
    margin: 4px 4px 0 0;
}

/* Section headers */
.section-title {
    font-size: 1.55rem; font-weight: 700;
    color: #2e7d32;
    border-left: 5px solid #2e7d32;
    padding-left: 14px;
    margin-bottom: 24px;
}
.section-subtitle {
    font-size: 0.92rem; color: #666;
    margin-top: -18px;
    margin-bottom: 24px;
    padding-left: 20px;
}

/* Module cards */
.module-card {
    border: none;
    border-radius: 10px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    transition: transform 0.2s, box-shadow 0.2s;
    height: 100%;
}
.module-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.13);
}
.module-card .icon-wrap {
    width: 52px; height: 52px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem;
    margin-bottom: 14px;
}
.module-card h5 { font-size: 1rem; font-weight: 700; margin-bottom: 6px; }
.module-card p  { font-size: 0.85rem; color: #555; margin-bottom: 0; }

/* Benefit row */
.benefit-item {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 16px 0; border-bottom: 1px solid #f0f0f0;
}
.benefit-item:last-child { border-bottom: none; }
.benefit-icon {
    flex-shrink: 0; width: 44px; height: 44px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.15rem;
}

/* Flow diagram */
.flow-wrap { overflow-x: auto; }
.flow-diagram {
    display: flex; align-items: center; gap: 0;
    min-width: 700px;
    padding: 10px 0;
}
.flow-step {
    flex: 1;
    background: #f7faf7;
    border: 2px solid #a5d6a7;
    border-radius: 10px;
    padding: 14px 10px;
    text-align: center;
    font-size: 0.82rem;
    font-weight: 600;
    color: #2e7d32;
    position: relative;
}
.flow-step .step-icon { font-size: 1.5rem; display: block; margin-bottom: 4px; }
.flow-step.highlight {
    background: #2e7d32;
    color: #fff;
    border-color: #2e7d32;
}
.flow-arrow {
    flex-shrink: 0; width: 28px;
    text-align: center;
    color: #66bb6a;
    font-size: 1.2rem;
}

/* Hierarchy SVG */
.hier-wrap { background: #f9fdf9; border-radius: 10px; padding: 24px; }

/* Stats bar */
.stat-pill {
    background: #e8f5e9; border-radius: 10px;
    padding: 18px 24px; text-align: center;
}
.stat-pill .num { font-size: 2rem; font-weight: 800; color: #2e7d32; line-height: 1; }
.stat-pill .lbl { font-size: 0.78rem; color: #555; margin-top: 4px; }

/* Tech stack */
.tech-badge {
    display: inline-flex; align-items: center; gap: 8px;
    background: #fff; border: 1px solid #e0e0e0;
    border-radius: 8px; padding: 8px 16px;
    font-size: 0.85rem; font-weight: 600;
    color: #333; margin: 4px;
}

/* CTA */
.cta-box {
    background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%);
    color: #fff; border-radius: 12px;
    padding: 48px 40px; text-align: center;
}
.cta-box h3 { font-size: 1.8rem; font-weight: 700; }
.cta-box p  { opacity: .88; max-width: 520px; margin: 0 auto 28px; }

/* Print tweaks */
@media print {
    .pres-hero { background: #2e7d32 !important; -webkit-print-color-adjust: exact; }
}
</style>

<div class="content-wrapper">

    <!-- ── HERO ── -->
    <div class="pres-hero">
        <h1><i class="bi bi-seedling me-2"></i>erpAgro</h1>
        <p><?php echo __('pres_hero_tagline'); ?></p>
        <div class="mt-3">
            <span class="badge-pill"><i class="bi bi-check2-circle me-1"></i><?php echo __('pres_badge_multi_company'); ?></span>
            <span class="badge-pill"><i class="bi bi-check2-circle me-1"></i><?php echo __('pres_badge_end_to_end'); ?></span>
            <span class="badge-pill"><i class="bi bi-check2-circle me-1"></i><?php echo __('pres_badge_ai'); ?></span>
            <span class="badge-pill"><i class="bi bi-check2-circle me-1"></i><?php echo __('pres_badge_realtime'); ?></span>
            <span class="badge-pill"><i class="bi bi-check2-circle me-1"></i><?php echo __('pres_badge_web'); ?></span>
        </div>
    </div>

    <!-- ── KEY STATS ── -->
    <div class="row g-3 mb-5">
        <div class="col-6 col-md-3"><div class="stat-pill"><div class="num">12+</div><div class="lbl"><?php echo __('pres_stat_modules'); ?></div></div></div>
        <div class="col-6 col-md-3"><div class="stat-pill"><div class="num">60+</div><div class="lbl"><?php echo __('pres_stat_submenus'); ?></div></div></div>
        <div class="col-6 col-md-3"><div class="stat-pill"><div class="num">5</div><div class="lbl"><?php echo __('pres_stat_hierarchy'); ?></div></div></div>
        <div class="col-6 col-md-3"><div class="stat-pill"><div class="num">360°</div><div class="lbl"><?php echo __('pres_stat_visibility'); ?></div></div></div>
    </div>

    <!-- ── PROBLEM & SOLUTION ── -->
    <div class="row g-4 mb-5">
        <div class="col-12">
            <h2 class="section-title"><?php echo __('pres_why_title'); ?></h2>
            <p class="section-subtitle"><?php echo __('pres_why_subtitle'); ?></p>
        </div>
        <div class="col-md-6">
            <div class="card h-100 border-danger-subtle">
                <div class="card-header" style="background:#c62828;color:#fff;">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo __('pres_challenges_header'); ?>
                </div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item"><i class="bi bi-x-circle-fill text-danger me-2"></i><?php echo __('pres_challenge_1'); ?></li>
                    <li class="list-group-item"><i class="bi bi-x-circle-fill text-danger me-2"></i><?php echo __('pres_challenge_2'); ?></li>
                    <li class="list-group-item"><i class="bi bi-x-circle-fill text-danger me-2"></i><?php echo __('pres_challenge_3'); ?></li>
                    <li class="list-group-item"><i class="bi bi-x-circle-fill text-danger me-2"></i><?php echo __('pres_challenge_4'); ?></li>
                    <li class="list-group-item"><i class="bi bi-x-circle-fill text-danger me-2"></i><?php echo __('pres_challenge_5'); ?></li>
                    <li class="list-group-item"><i class="bi bi-x-circle-fill text-danger me-2"></i><?php echo __('pres_challenge_6'); ?></li>
                </ul>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100 border-success">
                <div class="card-header" style="background:#2e7d32;color:#fff;">
                    <i class="bi bi-check-circle-fill me-2"></i><?php echo __('pres_solutions_header'); ?>
                </div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item"><i class="bi bi-check-circle-fill text-success me-2"></i><?php echo __('pres_solution_1'); ?></li>
                    <li class="list-group-item"><i class="bi bi-check-circle-fill text-success me-2"></i><?php echo __('pres_solution_2'); ?></li>
                    <li class="list-group-item"><i class="bi bi-check-circle-fill text-success me-2"></i><?php echo __('pres_solution_3'); ?></li>
                    <li class="list-group-item"><i class="bi bi-check-circle-fill text-success me-2"></i><?php echo __('pres_solution_4'); ?></li>
                    <li class="list-group-item"><i class="bi bi-check-circle-fill text-success me-2"></i><?php echo __('pres_solution_5'); ?></li>
                    <li class="list-group-item"><i class="bi bi-check-circle-fill text-success me-2"></i><?php echo __('pres_solution_6'); ?></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- ── BUSINESS FLOW ── -->
    <div class="mb-5">
        <h2 class="section-title"><?php echo __('pres_flow_title'); ?></h2>
        <p class="section-subtitle"><?php echo __('pres_flow_subtitle'); ?></p>
        <div class="flow-wrap">
            <div class="flow-diagram">
                <div class="flow-step">
                    <span class="step-icon">🌱</span>
                    <?php echo __('pres_flow_nursery'); ?>
                </div>
                <div class="flow-arrow">→</div>
                <div class="flow-step">
                    <span class="step-icon">🌴</span>
                    <?php echo __('pres_flow_estate'); ?>
                </div>
                <div class="flow-arrow">→</div>
                <div class="flow-step highlight">
                    <span class="step-icon">🧺</span>
                    <?php echo __('pres_flow_harvest'); ?>
                </div>
                <div class="flow-arrow">→</div>
                <div class="flow-step">
                    <span class="step-icon">⚙️</span>
                    <?php echo __('pres_flow_mill'); ?>
                </div>
                <div class="flow-arrow">→</div>
                <div class="flow-step">
                    <span class="step-icon">🛢️</span>
                    <?php echo __('pres_flow_stock'); ?>
                </div>
                <div class="flow-arrow">→</div>
                <div class="flow-step">
                    <span class="step-icon">🚚</span>
                    <?php echo __('pres_flow_sales'); ?>
                </div>
                <div class="flow-arrow">→</div>
                <div class="flow-step highlight">
                    <span class="step-icon">💰</span>
                    <?php echo __('pres_flow_payment'); ?>
                </div>
            </div>
        </div>
        <div class="mt-3 d-flex flex-wrap gap-2 align-items-center">
            <span class="badge bg-success"><?php echo __('pres_flow_badge'); ?></span>
            <span class="text-muted small"><?php echo __('pres_flow_badge_desc'); ?></span>
        </div>
    </div>

    <!-- ── 5-LEVEL HIERARCHY ── -->
    <div class="mb-5">
        <h2 class="section-title"><?php echo __('pres_hier_title'); ?></h2>
        <p class="section-subtitle"><?php echo __('pres_hier_subtitle'); ?></p>
        <div class="hier-wrap">
            <svg viewBox="0 0 780 320" xmlns="http://www.w3.org/2000/svg" style="width:100%;max-width:780px;display:block;margin:auto;">
                <defs>
                    <marker id="arr" markerWidth="8" markerHeight="8" refX="4" refY="4" orient="auto">
                        <path d="M1,1 L7,4 L1,7 Z" fill="#66bb6a"/>
                    </marker>
                </defs>
                <!-- Level boxes -->
                <!-- L1 Company -->
                <rect x="300" y="10" width="180" height="44" rx="8" fill="#2e7d32"/>
                <text x="390" y="28" text-anchor="middle" fill="#fff" font-size="11" font-weight="bold"><?php echo __('pres_hier_company'); ?></text>
                <text x="390" y="46" text-anchor="middle" fill="#c8e6c9" font-size="10"><?php echo __('pres_hier_company_ex'); ?></text>

                <!-- L1→L2 arrows -->
                <line x1="390" y1="54" x2="160" y2="100" stroke="#66bb6a" stroke-width="1.5" marker-end="url(#arr)"/>
                <line x1="390" y1="54" x2="390" y2="100" stroke="#66bb6a" stroke-width="1.5" marker-end="url(#arr)"/>
                <line x1="390" y1="54" x2="620" y2="100" stroke="#66bb6a" stroke-width="1.5" marker-end="url(#arr)"/>

                <!-- L2 Business Units -->
                <rect x="60"  y="100" width="200" height="44" rx="8" fill="#388e3c"/>
                <text x="160" y="118" text-anchor="middle" fill="#fff" font-size="11" font-weight="bold"><?php echo __('pres_hier_bu_estate'); ?></text>
                <text x="160" y="136" text-anchor="middle" fill="#c8e6c9" font-size="10"><?php echo __('pres_hier_bu_estate_ex'); ?></text>

                <rect x="290" y="100" width="200" height="44" rx="8" fill="#388e3c"/>
                <text x="390" y="118" text-anchor="middle" fill="#fff" font-size="11" font-weight="bold"><?php echo __('pres_hier_bu_mill'); ?></text>
                <text x="390" y="136" text-anchor="middle" fill="#c8e6c9" font-size="10"><?php echo __('pres_hier_bu_mill_ex'); ?></text>

                <rect x="520" y="100" width="200" height="44" rx="8" fill="#388e3c"/>
                <text x="620" y="118" text-anchor="middle" fill="#fff" font-size="11" font-weight="bold"><?php echo __('pres_hier_bu_nursery'); ?></text>
                <text x="620" y="136" text-anchor="middle" fill="#c8e6c9" font-size="10"><?php echo __('pres_hier_bu_nursery_ex'); ?></text>

                <!-- L2→L3 (only Estate shown) -->
                <line x1="160" y1="144" x2="160" y2="188" stroke="#66bb6a" stroke-width="1.5" marker-end="url(#arr)"/>
                <line x1="390" y1="144" x2="390" y2="188" stroke="#66bb6a" stroke-width="1.5" marker-end="url(#arr)" stroke-dasharray="4,3"/>

                <!-- L3 Divisions -->
                <rect x="60" y="188" width="200" height="40" rx="8" fill="#558b2f"/>
                <text x="160" y="204" text-anchor="middle" fill="#fff" font-size="11" font-weight="bold"><?php echo __('pres_hier_division'); ?></text>
                <text x="160" y="220" text-anchor="middle" fill="#dcedc8" font-size="10"><?php echo __('pres_hier_division_ex'); ?></text>

                <rect x="290" y="188" width="200" height="40" rx="8" fill="#558b2f" opacity=".6"/>
                <text x="390" y="204" text-anchor="middle" fill="#fff" font-size="11"><?php echo __('pres_hier_proc_unit'); ?></text>
                <text x="390" y="220" text-anchor="middle" fill="#dcedc8" font-size="10"><?php echo __('pres_hier_proc_unit_ex'); ?></text>

                <!-- L3→L4 -->
                <line x1="160" y1="228" x2="160" y2="268" stroke="#66bb6a" stroke-width="1.5" marker-end="url(#arr)"/>

                <!-- L4 Planting Year -->
                <rect x="60" y="268" width="200" height="40" rx="8" fill="#7cb342"/>
                <text x="160" y="284" text-anchor="middle" fill="#fff" font-size="11" font-weight="bold"><?php echo __('pres_hier_planting_year'); ?></text>
                <text x="160" y="300" text-anchor="middle" fill="#f9fbe7" font-size="10"><?php echo __('pres_hier_planting_year_ex'); ?></text>

                <!-- L4→L5 -->
                <line x1="160" y1="308" x2="160" y2="268" stroke="none"/>
                <line x1="260" y1="288" x2="360" y2="288" stroke="#66bb6a" stroke-width="1.5" marker-end="url(#arr)"/>

                <!-- L5 Block -->
                <rect x="360" y="268" width="200" height="40" rx="8" fill="#8bc34a"/>
                <text x="460" y="284" text-anchor="middle" fill="#fff" font-size="11" font-weight="bold"><?php echo __('pres_hier_block'); ?></text>
                <text x="460" y="300" text-anchor="middle" fill="#f9fbe7" font-size="10"><?php echo __('pres_hier_block_ex'); ?></text>

                <!-- Legend -->
                <text x="590" y="270" font-size="10" fill="#666"><?php echo __('pres_hier_legend_solid'); ?></text>
                <text x="590" y="284" font-size="10" fill="#aaa"><?php echo __('pres_hier_legend_dash'); ?></text>
            </svg>
        </div>
    </div>

    <!-- ── MODULES ── -->
    <div class="mb-5">
        <h2 class="section-title"><?php echo __('pres_modules_title'); ?></h2>
        <p class="section-subtitle"><?php echo __('pres_modules_subtitle'); ?></p>
        <div class="row g-3">
            <?php
            $modules = [
                ['🌱','bg-success bg-opacity-10 text-success', __('pres_mod_master'),     __('pres_mod_master_desc')],
                ['💰','bg-warning bg-opacity-10 text-warning', __('pres_mod_budget'),     __('pres_mod_budget_desc')],
                ['🛒','bg-info bg-opacity-10 text-info',       __('pres_mod_proc'),       __('pres_mod_proc_desc')],
                ['🌴','bg-success bg-opacity-10 text-success', __('pres_mod_estate'),     __('pres_mod_estate_desc')],
                ['🧺','bg-orange bg-opacity-10',               __('pres_mod_harvest'),    __('pres_mod_harvest_desc')],
                ['⚙️','bg-secondary bg-opacity-10 text-secondary', __('pres_mod_mill'),  __('pres_mod_mill_desc')],
                ['🛢️','bg-primary bg-opacity-10 text-primary', __('pres_mod_inventory'), __('pres_mod_inventory_desc')],
                ['🚚','bg-danger bg-opacity-10 text-danger',   __('pres_mod_sales'),     __('pres_mod_sales_desc')],
                ['🏦','bg-info bg-opacity-10 text-info',       __('pres_mod_finance'),   __('pres_mod_finance_desc')],
                ['🌿','bg-success bg-opacity-10 text-success', __('pres_mod_nursery'),   __('pres_mod_nursery_desc')],
                ['👥','bg-purple',                             __('pres_mod_plasma'),    __('pres_mod_plasma_desc')],
                ['📊','bg-warning bg-opacity-10 text-warning', __('pres_mod_reports'),   __('pres_mod_reports_desc')],
            ];
            foreach ($modules as [$icon,$cls,$name,$desc]): ?>
            <div class="col-sm-6 col-lg-4">
                <div class="card module-card p-3">
                    <div class="icon-wrap <?php echo $cls; ?>"><?php echo $icon; ?></div>
                    <h5><?php echo $name; ?></h5>
                    <p><?php echo $desc; ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── VALUE PROPOSITION ── -->
    <div class="mb-5">
        <h2 class="section-title"><?php echo __('pres_benefits_title'); ?></h2>
        <p class="section-subtitle"><?php echo __('pres_benefits_subtitle'); ?></p>
        <div class="row g-4">
            <div class="col-md-6">
                <div class="card h-100 p-4">
                    <?php
                    $benefits = [
                        ['#2e7d32','bi-graph-up-arrow', __('pres_ben_efficiency'), __('pres_ben_efficiency_desc')],
                        ['#1565c0','bi-eye',            __('pres_ben_visibility'), __('pres_ben_visibility_desc')],
                        ['#e65100','bi-shield-check',   __('pres_ben_budget'),    __('pres_ben_budget_desc')],
                        ['#6a1b9a','bi-people',         __('pres_ben_plasma'),    __('pres_ben_plasma_desc')],
                    ];
                    foreach ($benefits as [$color,$icon,$title,$desc]): ?>
                    <div class="benefit-item">
                        <div class="benefit-icon" style="background:<?php echo $color; ?>22;">
                            <i class="bi <?php echo $icon; ?>" style="color:<?php echo $color; ?>;"></i>
                        </div>
                        <div>
                            <strong><?php echo $title; ?></strong>
                            <p class="mb-0 text-muted" style="font-size:.88rem;"><?php echo $desc; ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100 p-4">
                    <?php
                    $benefits2 = [
                        ['#2e7d32','bi-cash-coin',      __('pres_ben_accuracy'), __('pres_ben_accuracy_desc')],
                        ['#00695c','bi-bar-chart-line', __('pres_ben_analytics'),__('pres_ben_analytics_desc')],
                        ['#c62828','bi-cpu',            __('pres_ben_ai'),       __('pres_ben_ai_desc')],
                        ['#4527a0','bi-cloud-check',    __('pres_ben_web'),      __('pres_ben_web_desc')],
                    ];
                    foreach ($benefits2 as [$color,$icon,$title,$desc]): ?>
                    <div class="benefit-item">
                        <div class="benefit-icon" style="background:<?php echo $color; ?>22;">
                            <i class="bi <?php echo $icon; ?>" style="color:<?php echo $color; ?>;"></i>
                        </div>
                        <div>
                            <strong><?php echo $title; ?></strong>
                            <p class="mb-0 text-muted" style="font-size:.88rem;"><?php echo $desc; ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── TECH STACK ── -->
    <div class="mb-5">
        <h2 class="section-title"><?php echo __('pres_tech_title'); ?></h2>
        <p class="section-subtitle"><?php echo __('pres_tech_subtitle'); ?></p>
        <div class="card p-4">
            <div class="row g-4">
                <div class="col-md-4">
                    <h6 class="fw-bold text-muted mb-2"><?php echo __('pres_tech_backend'); ?></h6>
                    <span class="tech-badge"><i class="bi bi-filetype-php text-primary"></i> PHP 8.x</span>
                    <span class="tech-badge"><i class="bi bi-database text-success"></i> MariaDB / MySQL</span>
                    <span class="tech-badge"><i class="bi bi-hdd-server text-secondary"></i> Apache</span>
                </div>
                <div class="col-md-4">
                    <h6 class="fw-bold text-muted mb-2"><?php echo __('pres_tech_frontend'); ?></h6>
                    <span class="tech-badge"><i class="bi bi-bootstrap text-purple"></i> Bootstrap 5</span>
                    <span class="tech-badge"><i class="bi bi-icons text-info"></i> Bootstrap Icons</span>
                    <span class="tech-badge"><i class="bi bi-braces text-warning"></i> Vanilla JS</span>
                </div>
                <div class="col-md-4">
                    <h6 class="fw-bold text-muted mb-2"><?php echo __('pres_tech_deployment'); ?></h6>
                    <span class="tech-badge"><i class="bi bi-cloud-upload text-success"></i> Cloud / On-Premise</span>
                    <span class="tech-badge"><i class="bi bi-hdd-network text-secondary"></i> cPanel / VPS</span>
                    <span class="tech-badge"><i class="bi bi-shield-lock text-danger"></i> HTTPS Ready</span>
                </div>
            </div>
            <hr>
            <div class="row g-3 mt-1">
                <div class="col-sm-4">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-check-circle-fill text-success fs-5"></i>
                        <div><strong><?php echo __('pres_tech_multi_company'); ?></strong><br><small class="text-muted"><?php echo __('pres_tech_multi_company_sub'); ?></small></div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-check-circle-fill text-success fs-5"></i>
                        <div><strong><?php echo __('pres_tech_rbac'); ?></strong><br><small class="text-muted"><?php echo __('pres_tech_rbac_sub'); ?></small></div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-check-circle-fill text-success fs-5"></i>
                        <div><strong><?php echo __('pres_tech_bilingual'); ?></strong><br><small class="text-muted"><?php echo __('pres_tech_bilingual_sub'); ?></small></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── DEPLOYMENT ROADMAP ── -->
    <div class="mb-5">
        <h2 class="section-title"><?php echo __('pres_roadmap_title'); ?></h2>
        <p class="section-subtitle"><?php echo __('pres_roadmap_subtitle'); ?></p>
        <div class="row g-3">
            <?php
            $phases = [
                ['1','#2e7d32', __('pres_phase1_title'), [__('pres_phase1_1'), __('pres_phase1_2'), __('pres_phase1_3'), __('pres_phase1_4')]],
                ['2','#1565c0', __('pres_phase2_title'), [__('pres_phase2_1'), __('pres_phase2_2'), __('pres_phase2_3'), __('pres_phase2_4')]],
                ['3','#6a1b9a', __('pres_phase3_title'), [__('pres_phase3_1'), __('pres_phase3_2'), __('pres_phase3_3'), __('pres_phase3_4')]],
            ];
            foreach ($phases as [$num,$color,$title,$items]): ?>
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-header" style="background:<?php echo $color; ?>;color:#fff;">
                        <strong><?php echo sprintf(__('pres_phase_label'), $num); ?></strong> — <?php echo explode(': ', $title)[1] ?? $title; ?>
                    </div>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($items as $item): ?>
                        <li class="list-group-item" style="font-size:.9rem;">
                            <i class="bi bi-arrow-right-circle-fill me-2" style="color:<?php echo $color; ?>;"></i><?php echo $item; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── CTA ── -->
    <div class="cta-box mb-4 no-print">
        <h3><i class="bi bi-rocket-takeoff me-2"></i><?php echo __('pres_cta_heading'); ?></h3>
        <p><?php echo __('pres_cta_desc'); ?></p>
        <a href="index.php" class="btn btn-light btn-lg me-2"><i class="bi bi-speedometer2 me-1"></i><?php echo __('pres_cta_btn_dashboard'); ?></a>
        <a href="qna.php" class="btn btn-outline-light btn-lg"><i class="bi bi-chat-dots me-1"></i><?php echo __('pres_cta_btn_qna'); ?></a>
    </div>

</div><!-- /content-wrapper -->

<?php require_once 'includes/footer.php'; ?>
