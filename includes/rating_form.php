<?php
/**
 * includes/rating_form.php — the Maisha Bora rating form, shared by
 *   - index.php  ("New assessment") and
 *   - edit.php   ("Edit and recalculate a stored record").
 *
 * The POST field names — individual_name … rater_known_for, is_mute and
 * {subscale}_item_{n} — are the scoring contract consumed by score.php.
 * Never rename them here.
 */

// escaper that also works when ui.php has not been loaded
if (!function_exists('gars_e')) {
    function gars_e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

/** The six subscales with their exact item wordings (the published instrument). */
function gars_subscale_defs(): array
{
    return [
        [
            "id" => "rb",
            "name" => "Restricted/Repetitive Behaviors",
            "short" => "RB",
            "questions" => [
                "If left alone, the majority of the individual's time will be spent in repetitive or stereotyped behaviors.",
                "Is preoccupied with specific stimuli that are abnormal in intensity.",
                "Stares at hands, objects, or items in the environment for at least 5 seconds.",
                "Flicks fingers rapidly in front of eyes for periods of 5 seconds or more.",
                "Makes rapid lunging, darting movements when moving from place to place.",
                "Flaps hands or fingers in front of face or at sides.",
                "Makes high-pitched sounds (e.g., eee-eee-eee-eee) or other vocalizations for self-stimulation.",
                "Uses toys or objects inappropriately (e.g., spins cars, takes action toys apart).",
                "Does certain things repetitively, ritualistically.",
                "Engages in stereotyped behaviors when playing with toys or objects.",
                "Repeats unintelligible sounds (babbles) over and over.",
                "Shows unusual interest in sensory aspects of play materials, body parts, or objects.",
                "Displays ritualistic or compulsive behaviors.",
            ],
        ],
        [
            "id" => "si",
            "name" => "Social Interaction",
            "short" => "SI",
            "questions" => [
                "Does not initiate conversations with peers or others.",
                "Pays little or no attention to what peers are doing.",
                "Fails to imitate other people in games or learning activities.",
                "Doesn't follow other's gestures (cues) to look at something (e.g., when other person nods head, points, or uses other body language cues).",
                "Seems indifferent to other person's attention (doesn't try to get, maintain, or direct the other person's attention).",
                "Shows minimal expressed pleasure when interacting with others.",
                "Displays little or no excitement in showing toys or objects to others.",
                "Seems uninterested in pointing out things in the environment to others.",
                "Seems unwilling or reluctant to get others to interact with him or her.",
                "Shows minimal or no response when others attempt to interact with him or her.",
                "Displays little or no reciprocal social communication (e.g., doesn't voluntarily say \"bye-bye\" in response to another person saying \"bye-bye\" to him or her).",
                "Doesn't try to make friends with other people.",
                "Fails to engage in creative, imaginative play.",
                "Shows little or no interest in other people.",
            ],
        ],
        [
            "id" => "sc",
            "name" => "Social Communication",
            "short" => "SC",
            "questions" => [
                "Responds inappropriately to heinous stimuli (e.g., doesn't laugh at jokes, cartoons, funny stories).",
                "Has difficulty understanding jokes.",
                "Has difficulty understanding sting expressions.",
                "Has difficulty identifying when someone is teasing.",
                "Has difficulty understanding when he or she is being ridiculed.",
                "Has difficulty understanding what causes people to dislike him or her.",
                "Fails to predict probable consequences in social events.",
                "Doesn't seem to understand that people have thoughts and feelings different from his or hers.",
                "Doesn't seem to understand that the other person doesn't know something.",
            ],
        ],
        [
            "id" => "er",
            "name" => "Emotional Responses",
            "short" => "ER",
            "questions" => [
                "Needs an excessive amount of reassurance if things are changed or go wrong.",
                "Becomes frustrated quickly when he or she cannot do something.",
                "Temper tantrums when frustrated.",
                "Becomes upset when routines are changed.",
                "Responds negatively when given commands, requests, or directions.",
                "Has extreme reactions (e.g., cries, screams, tantrums) in response to loud, unexpected noise.",
                "Temper tantrums when doesn't get his or her way.",
                "Temper tantrums when told to stop doing something he or she enjoys doing.",
            ],
        ],
        [
            "id" => "cs",
            "name" => "Cognitive Style",
            "short" => "CS",
            "questions" => [
                "Uses exceptionally precise speech.",
                "Attaches very concrete meanings to words.",
                "Talks about a single subject excessively.",
                "Displays superior knowledge or skill in specific subjects.",
                "Displays excellent memory.",
                "Shows an intense, obsessive interest in specific intellectual subjects.",
                "Makes naive remarks (unaware of reaction produced in others).",
            ],
        ],
        [
            "id" => "ms",
            "name" => "Maladaptive Speech",
            "short" => "MS",
            "questions" => [
                "Repeats (echoes) words or phrases verbally or with signs.",
                "Repeats words out of context (repeats words or phrases heard at an earlier time).",
                "Speaks (or signs) with flat tone, affect.",
                "Uses \"yes\" and \"no\" inappropriately. Says \"yes\" when asked if he or she wants an aversive stimulus or says \"no\" when asked if he or she wants a favorite toy or treat.",
                "Uses \"he\" or \"she\" instead of \"I\" when referring to self.",
                "Speech is abnormal in tone, volume, or rate.",
                "Utters idiosyncratic words or phrases that have no meaning to others.",
            ],
        ],
    ];
}

/** <style> for the rating form: print inside <head> after gars_styles(). */
function gars_rating_form_assets(): void
{
    ?><style>
        /* ---------------- rating-form page ---------------- */
        .sheet .form-block{ padding:26px 30px; }
        .fb-head{ display:flex; gap:16px; align-items:flex-start; margin-bottom:20px; }
        .fb-head .fb-no{ flex:none; width:34px; height:34px; border:1.5px solid var(--ink); border-radius:4px; display:flex; align-items:center; justify-content:center; font-family:var(--display); font-weight:700; font-size:1rem; }
        .fb-head h2{ font-family:var(--display); font-weight:600; font-size:1.3rem; line-height:1.2; }
        .fb-head .fb-sub{ color:var(--muted); font-size:.9rem; margin-top:4px; max-width:52em; }

        /* the 0-3 mark box — the instrument's own vocabulary */
        .how-scale{ display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:10px 18px; margin-top:14px; }
        .how-scale li{ display:flex; align-items:center; gap:12px; font-size:.88rem; color:var(--ink); list-style:none; }
        .how-scale .mk{ flex:none; width:30px; height:30px; border:1.5px solid var(--rule-strong); border-radius:4px; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:.9rem; color:var(--muted); }
        .how{ border:1px solid var(--rule); border-left:3px solid var(--brand); border-radius:3px; background:var(--brand-soft); padding:16px 18px; margin-bottom:22px; font-size:.93rem; }

        .scale{ margin-bottom:10px; }
        .scale + .scale{ margin-top:30px; }
        .scale:last-child{ margin-bottom:0; }
        .scale-head{ display:flex; align-items:baseline; gap:12px; flex-wrap:wrap; padding-bottom:12px; border-bottom:1.5px solid var(--ink); margin-bottom:4px; }
        .scale-code{ font-family:var(--display); font-weight:700; font-size:.82rem; letter-spacing:.03em; color:#fff; background:var(--brand); border-radius:3px; padding:4px 8px; align-self:center; }
        .scale-title{ font-family:var(--display); font-weight:600; font-size:1.18rem; }
        .scale-count{ color:var(--muted); font-size:.85rem; margin-left:auto; }

        .item{ display:grid; grid-template-columns:30px 1fr 178px; gap:16px; align-items:center; padding:11px 0; border-bottom:1px solid var(--rule); }
        .item:last-of-type{ border-bottom:none; }
        .item .num{ text-align:right; color:var(--muted); font-size:.82rem; font-variant-numeric:tabular-nums; }
        .item .desc{ font-size:.94rem; line-height:1.5; overflow-wrap:anywhere; }
        .marks{ display:flex; gap:8px; justify-content:flex-end; }
        .mark input{ position:absolute; opacity:0; width:19px; height:19px; margin:0; }
        .mark span{ display:flex; align-items:center; justify-content:center; width:34px; height:34px; border:1.5px solid var(--rule-strong); border-radius:4px; font-weight:600; font-size:.9rem; color:var(--muted); cursor:pointer; transition:background .12s, color .12s, border-color .12s; }
        .mark:hover span{ border-color:var(--brand); color:var(--brand); }
        .mark input:checked + span{ background:var(--brand); border-color:var(--brand); color:#fff; }
        .mark input:focus-visible + span{ outline:2px solid var(--brand); outline-offset:2px; }

        .subtotal{ display:flex; justify-content:space-between; align-items:baseline; gap:12px; background:var(--brand-soft); border-radius:3px; padding:12px 16px; margin-top:14px; }
        .subtotal span{ color:var(--brand-deep); font-weight:600; font-size:.9rem; }
        .subtotal .st-n{ font-size:.94rem; font-variant-numeric:tabular-nums; }
        .subtotal .st-n i{ font-style:normal; color:var(--muted); font-weight:500; }

        /* dimmed subscales when the individual is mute */
        .scale.is-off{ opacity:.4; pointer-events:none; }

        /* mute question */
        .mute-block{ background:var(--amber-soft); }
        .mute-choice{ display:flex; gap:14px; flex-wrap:wrap; margin-top:4px; }
        .mute-opt{ display:flex; align-items:center; gap:10px; padding:12px 18px; border:1px solid var(--rule-strong); border-radius:3px; background:var(--surface); cursor:pointer; font-weight:500; }
        .mute-opt:hover{ border-color:var(--amber); }
        .mute-opt:has(input:checked){ border-color:var(--amber); box-shadow:0 0 0 2px rgba(255,186,25,.28); }
        .mute-opt input{ accent-color:var(--amber); width:18px; height:18px; }
        .mute-note{ margin-top:14px; color:#92400e; font-size:.92rem; }

        .submit-row{ padding:26px 30px; display:flex; align-items:center; gap:18px; flex-wrap:wrap; }
        .submit-row .hint{ color:var(--muted); font-size:.88rem; }

        /* required-fields meter, pinned to the bottom edge */
        .formbar{ position:sticky; bottom:0; z-index:40; background:var(--surface); border-top:1px solid var(--rule); box-shadow:0 -6px 24px rgba(11,92,175,.08); }
        .fb-in{ max-width:900px; margin:0 auto; padding:10px 24px; display:flex; align-items:center; gap:14px; }
        .fb-meter{ flex:1; height:4px; border-radius:99px; background:var(--rule); overflow:hidden; }
        .fb-meter span{ display:block; height:100%; background:var(--brand); border-radius:99px; transition:width .25s; }
        .formbar.done .fb-meter span{ background:var(--ok); }
        .fb-text{ font-size:.86rem; font-weight:600; color:var(--muted); white-space:nowrap; }
        .formbar.done .fb-text{ color:var(--ok); }
        .complete-mark{ display:none; }
        .formbar.done .complete-mark{ display:inline; }
        .formbar.done .missing-count{ display:none; }

        @media (max-width:640px){
            .sheet .form-block{ padding:18px 16px; }
            .item{ grid-template-columns:24px 1fr; }
            .marks{ grid-column:1 / -1; width:100%; justify-content:space-between; }
            .mark span{ width:100%; min-width:60px; height:40px; }
            .fb-head .fb-no{ display:none; }
            .submit-row{ padding:20px 16px; }
            .fb-in{ padding:10px 16px; }
        }
    </style><?php
}

/**
 * Print the rating <form>, the required-fields meter and its script, then close <main>.
 * Call it from inside <main class="wrap" id="main">.
 *
 * $prefill = [
 *   'fields' => [ field-name => value ],
 *   'marks'  => [ '{id}_item_{n}' => 0..3 ],
 *   'is_mute'=> 'yes'|'no',
 * ]
 */
function gars_rating_form_body(string $actionUrl, string $submitLabel = 'Score and save', array $prefill = []): void
{
    $subscales = gars_subscale_defs();
    $fields = $prefill['fields'] ?? [];
    $marks = $prefill['marks'] ?? [];
    $muteSel = ($prefill['is_mute'] ?? 'no') === 'yes';

    $val = static fn (string $k): string => (string) ($fields[$k] ?? '');
    $markVal = static fn (string $sid, int $qi): ?int => isset($marks[$sid . '_item_' . $qi]) ? (int) $marks[$sid . '_item_' . $qi] : null;
    ?>
    <form action="<?php echo gars_e($actionUrl); ?>" method="POST" id="rating-form">
        <div class="sheet">

            <!-- Section 1: Identifying Information -->
            <div class="form-block">
                <div class="fb-head">
                    <span class="fb-no" aria-hidden="true">1</span>
                    <div>
                        <h2>Identifying information</h2>
                        <p class="fb-sub">Fields marked <span aria-hidden="true" style="color:var(--brick);font-weight:700;">*</span> are required. The record cannot be saved until every required field is filled in.</p>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="individual_name">Individual's name <span class="req">*</span></label>
                        <input type="text" id="individual_name" name="individual_name" required value="<?php echo gars_e($val('individual_name')); ?>">
                    </div>
                    <div class="form-group">
                        <label for="school">School <span class="req">*</span></label>
                        <input type="text" id="school" name="school" required value="<?php echo gars_e($val('school')); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="rater_name">Rater's name <span class="req">*</span></label>
                        <input type="text" id="rater_name" name="rater_name" required value="<?php echo gars_e($val('rater_name')); ?>">
                    </div>
                    <div class="form-group">
                        <label for="rater_title">Rater's title <span class="req">*</span></label>
                        <input type="text" id="rater_title" name="rater_title" required value="<?php echo gars_e($val('rater_title')); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="date_of_birth">Date of birth <span class="req">*</span></label>
                        <input type="date" id="date_of_birth" name="date_of_birth" required value="<?php echo gars_e($val('date_of_birth')); ?>">
                    </div>
                    <div class="form-group">
                        <label for="age">Age <span class="req">*</span></label>
                        <input type="text" id="age" name="age" required value="<?php echo gars_e($val('age')); ?>">
                    </div>
                    <div class="form-group">
                        <label for="gender">Gender <span class="req">*</span></label>
                        <select id="gender" name="gender" required>
                            <option value="">Select</option>
                            <option value="Female"<?php echo $val('gender') === 'Female' ? ' selected' : ''; ?>>Female</option>
                            <option value="Male"<?php echo $val('gender') === 'Male' ? ' selected' : ''; ?>>Male</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="grade">Grade</label>
                        <input type="text" id="grade" name="grade" value="<?php echo gars_e($val('grade')); ?>">
                    </div>
                    <div class="form-group">
                        <label for="examiner_name">Examiner's name <span class="req">*</span></label>
                        <input type="text" id="examiner_name" name="examiner_name" required value="<?php echo gars_e($val('examiner_name')); ?>">
                    </div>
                    <div class="form-group">
                        <label for="examiner_title">Examiner's title <span class="req">*</span></label>
                        <input type="text" id="examiner_title" name="examiner_title" required value="<?php echo gars_e($val('examiner_title')); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="date_of_rating">Date of rating <span class="req">*</span></label>
                        <input type="date" id="date_of_rating" name="date_of_rating" required value="<?php echo gars_e($val('date_of_rating')); ?>">
                    </div>
                    <div class="form-group">
                        <label for="rater_known_for">Rater has known the individual for</label>
                        <input type="text" id="rater_known_for" name="rater_known_for" placeholder="years / months" value="<?php echo gars_e($val('rater_known_for')); ?>">
                    </div>
                </div>
            </div>

            <!-- Section 2: rating items -->
            <div class="form-block">
                <div class="fb-head">
                    <span class="fb-no" aria-hidden="true">2</span>
                    <div>
                        <h2>Rating items</h2>
                        <p class="fb-sub">Mark how well each item describes the individual's typical behavior under ordinary circumstances.</p>
                    </div>
                </div>

                <div class="how">
                    <ul class="how-scale">
                        <li><span class="mk" aria-hidden="true">0</span>Not at all like the individual</li>
                        <li><span class="mk" aria-hidden="true">1</span>Not much like the individual</li>
                        <li><span class="mk" aria-hidden="true">2</span>Somewhat like the individual</li>
                        <li><span class="mk" aria-hidden="true">3</span>Very much like the individual</li>
                    </ul>
                </div>

                <?php foreach ($subscales as $subscale): ?>
                <div class="scale" data-sc="<?php echo $subscale['id']; ?>">
                    <div class="scale-head">
                        <span class="scale-code"><?php echo $subscale['short']; ?></span>
                        <h3 class="scale-title"><?php echo gars_e($subscale['name']); ?></h3>
                        <span class="scale-count"><?php echo count($subscale['questions']); ?> items</span>
                    </div>

                    <?php foreach ($subscale['questions'] as $qi => $item): ?>
                    <div class="item">
                        <span class="num" aria-hidden="true"><?php echo $qi + 1; ?></span>
                        <p class="desc"><?php echo gars_e($item); ?></p>
                        <div class="marks" role="radiogroup" aria-label="<?php echo gars_e($subscale['name']); ?>, item <?php echo $qi + 1; ?>">
                            <?php for ($v = 0; $v <= 3; $v++):
                                $sel = $markVal($subscale['id'], $qi);
                                $checked = ($sel === null && $v === 0) || $sel === $v;
                            ?>
                            <label class="mark">
                                <input type="radio" name="<?php echo $subscale['id']; ?>_item_<?php echo $qi; ?>" value="<?php echo $v; ?>"
                                       <?php echo $checked ? 'checked' : ''; ?>>
                                <span><?php echo $v; ?></span>
                            </label>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="subtotal">
                        <span>Subtotal, <?php echo $subscale['short']; ?></span>
                        <span class="st-n"><strong id="<?php echo $subscale['id']; ?>_subtotal">0</strong> <i>/ <?php echo count($subscale['questions']) * 3; ?></i></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Section 3: mute question -->
            <div class="form-block">
                <div class="fb-head">
                    <span class="fb-no" aria-hidden="true">3</span>
                    <div>
                        <h2>Mute question</h2>
                        <p class="fb-sub">If the individual is mute, the Cognitive Style and Maladaptive Speech items stay unmarked and the composite score is computed from the other 4 subscales.</p>
                    </div>
                </div>
                <div class="mute-choice">
                    <label class="mute-opt">
                        <input type="radio" name="is_mute" value="yes"<?php echo $muteSel ? ' checked' : ''; ?> onchange="toggleMute(this.value)">
                        <span>Yes, the individual is mute</span>
                    </label>
                    <label class="mute-opt">
                        <input type="radio" name="is_mute" value="no"<?php echo $muteSel ? '' : ' checked'; ?> onchange="toggleMute(this.value)">
                        <span>No, the individual is not mute</span>
                    </label>
                </div>
                <p class="mute-note" id="mute-note" hidden>Cognitive Style and Maladaptive Speech are dimmed below. Their ratings are still accepted but excluded from the composite.</p>
            </div>

            <!-- submit -->
            <div class="submit-row">
                <button type="submit" class="btn"><?php echo gars_e($submitLabel); ?></button>
                <p class="hint">Scoring converts raw marks to scaled scores and percentiles, then reports the Autism Index.</p>
            </div>
        </div>
    </form>
</main>

<!-- required-fields meter -->
<div class="formbar" id="formbar" role="status" aria-live="polite">
    <div class="fb-in">
        <span class="fb-text" id="req-status"><span class="missing-count">Checking required fields</span><span class="complete-mark">All required fields are filled</span></span>
        <div class="fb-meter" aria-hidden="true"><span id="req-meter" style="width:0%"></span></div>
    </div>
</div>

<script>
    function calculateSubtotals() {
        <?php foreach ($subscales as $subscale): ?>
        let <?php echo $subscale['id']; ?>_total = 0;
        for (let i = 0; i < <?php echo count($subscale['questions']); ?>; i++) {
            const selected = document.querySelector('input[name="<?php echo $subscale['id']; ?>_item_' + i + '"]:checked');
            if (selected) {
                <?php echo $subscale['id']; ?>_total += parseInt(selected.value);
            }
        }
        document.getElementById('<?php echo $subscale['id']; ?>_subtotal').textContent = <?php echo $subscale['id']; ?>_total;
        <?php endforeach; ?>
    }
    calculateSubtotals();
    document.querySelectorAll('#rating-form input[type="radio"]').forEach(radio => {
        radio.addEventListener('change', calculateSubtotals);
    });

    function toggleMute(value) {
        const muted = value === 'yes';
        document.querySelectorAll('.scale[data-sc="cs"], .scale[data-sc="ms"]').forEach(sec => {
            sec.classList.toggle('is-off', muted);
        });
        document.getElementById('mute-note').hidden = !muted;
    }
    // apply the current mute choice on load (relevant when editing a mute record)
    const muteInit = document.querySelector('#rating-form input[name="is_mute"]:checked');
    if (muteInit) toggleMute(muteInit.value);

    // required-fields meter
    const reqFields = document.querySelectorAll('#rating-form [required]');
    const meter = document.getElementById('req-meter');
    const bar = document.getElementById('formbar');
    function updateMeter() {
        let filled = 0;
        reqFields.forEach(f => { if (f.value.trim() !== '') filled++; });
        const total = reqFields.length;
        const missing = total - filled;
        document.querySelector('.missing-count').textContent =
            missing === 0 ? '' : (missing === 1 ? '1 required field left' : missing + ' required fields left');
        meter.style.width = (filled / total * 100) + '%';
        bar.classList.toggle('done', missing === 0);
    }
    reqFields.forEach(f => {
        f.addEventListener('input', updateMeter);
        f.addEventListener('change', updateMeter);
    });
    updateMeter();
</script>
    <?php
}
