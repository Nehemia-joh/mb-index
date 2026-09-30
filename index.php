<?php
// GARS-3 Rating Form
$subscales = [
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GARS-3 Rating Form</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: white; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden; }
        .header { background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%); color: white; padding: 30px; text-align: center; }
        .header h1 { font-size: 2em; margin-bottom: 10px; }
        .header p { font-size: 1.1em; opacity: 0.9; }
        .form-section { padding: 25px 30px; border-bottom: 1px solid #eee; }
        .form-section h2 { color: #2c3e50; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 3px solid #3498db; display: inline-block; }
        .form-row { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 15px; }
        .form-group { flex: 1; min-width: 200px; }
        .form-group label { display: block; margin-bottom: 5px; color: #555; font-weight: 600; font-size: 0.9em; }
        .form-group input, .form-group select { width: 100%; padding: 10px 12px; border: 2px solid #ddd; border-radius: 6px; font-size: 1em; transition: border-color 0.3s; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #3498db; }
        .subscale-section { background: #f8f9fa; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .subscale-title { color: #2c3e50; font-size: 1.3em; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 2px solid #3498db; }
        .item-row { display: flex; align-items: center; padding: 12px 15px; margin-bottom: 8px; background: white; border-radius: 6px; border: 1px solid #e0e0e0; transition: box-shadow 0.2s; }
        .item-row:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .item-number { background: #3498db; color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; margin-right: 15px; flex-shrink: 0; }
        .item-text { flex: 1; color: #333; line-height: 1.4; }
        .rating-options { display: flex; gap: 8px; flex-shrink: 0; }
        .rating-options label { display: flex; flex-direction: column; align-items: center; cursor: pointer; padding: 5px 10px; border-radius: 4px; transition: background 0.2s; }
        .rating-options label:hover { background: #e8f4f8; }
        .rating-options input[type="radio"] { margin-bottom: 3px; }
        .rating-options span { font-size: 0.75em; color: #666; }
        .mute-section { background: #fff3cd; border: 2px solid #ffc107; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .mute-section h3 { color: #856404; margin-bottom: 10px; }
        .mute-options { display: flex; gap: 20px; margin-top: 10px; }
        .mute-options label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 1.1em; }
        .mute-options input[type="radio"] { width: 20px; height: 20px; }
        .submit-btn { display: block; width: 100%; padding: 15px; background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%); color: white; border: none; border-radius: 8px; font-size: 1.2em; font-weight: bold; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; }
        .submit-btn:hover { transform: translateY(-2px); box-shadow: 0 5px 20px rgba(39, 174, 96, 0.4); }
        .instructions { background: #e8f4f8; border-left: 4px solid #3498db; padding: 15px; margin-bottom: 20px; border-radius: 0 6px 6px 0; }
        .instructions h4 { color: #2c3e50; margin-bottom: 8px; }
        .instructions p { color: #555; line-height: 1.6; }
        .subtotal-row { display: flex; justify-content: space-between; align-items: center; background: #2c3e50; color: white; padding: 12px 15px; border-radius: 6px; margin-top: 10px; font-weight: bold; }
        @media (max-width: 768px) {
            body { padding: 10px; }
            .header { padding: 20px 15px; }
            .header h1 { font-size: 1.4em; }
            .header p { font-size: 0.9em; }
            .form-section { padding: 15px; }
            .form-section h2 { font-size: 1.2em; }
            .form-row { flex-direction: column; gap: 10px; }
            .form-group { min-width: 100%; }
            .subscale-section { padding: 12px; }
            .subscale-title { font-size: 1.1em; }
            .item-row { flex-direction: column; align-items: flex-start; padding: 10px; }
            .item-number { margin-bottom: 8px; }
            .item-text { margin-bottom: 10px; font-size: 0.9em; }
            .rating-options { width: 100%; justify-content: space-between; }
            .rating-options label { flex: 1; padding: 8px 5px; }
            .mute-options { flex-direction: column; gap: 10px; }
            .mute-options label { font-size: 1em; }
            .submit-btn { font-size: 1em; padding: 12px; }
        }
        @media (max-width: 480px) {
            .header h1 { font-size: 1.2em; }
            .item-text { font-size: 0.85em; }
            .rating-options span { font-size: 0.7em; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>maishabora Autism Rating Scale - Third Edition</h1>
            <p>MB Summary/Response Form</p>
        </div>

        <form action="score.php" method="POST">
            <!-- Section 1: Identifying Information -->
            <div class="form-section">
                <h2>Section 1: Identifying Information</h2>
                <div class="form-row">
                    <div class="form-group">
                        <label for="individual_name">Individual's Name</label>
                        <input type="text" id="individual_name" name="individual_name">
                    </div>
                    <div class="form-group">
                        <label for="school">School</label>
                        <input type="text" id="school" name="school">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="rater_name">Rater's Name</label>
                        <input type="text" id="rater_name" name="rater_name">
                    </div>
                    <div class="form-group">
                        <label for="rater_title">Rater's Title</label>
                        <input type="text" id="rater_title" name="rater_title">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="date_of_birth">Date of Birth</label>
                        <input type="date" id="date_of_birth" name="date_of_birth">
                    </div>
                    <div class="form-group">
                        <label for="age">Age</label>
                        <input type="text" id="age" name="age">
                    </div>
                    <div class="form-group">
                        <label for="gender">Gender</label>
                        <select id="gender" name="gender">
                            <option value="">Select</option>
                            <option value="Female">Female</option>
                            <option value="Male">Male</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="grade">Grade</label>
                        <input type="text" id="grade" name="grade">
                    </div>
                    <div class="form-group">
                        <label for="examiner_name">Examiner's Name</label>
                        <input type="text" id="examiner_name" name="examiner_name">
                    </div>
                    <div class="form-group">
                        <label for="examiner_title">Examiner's Title</label>
                        <input type="text" id="examiner_title" name="examiner_title">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="date_of_rating">Date of GARS-3 Rating</label>
                        <input type="date" id="date_of_rating" name="date_of_rating">
                    </div>
                    <div class="form-group">
                        <label for="rater_known_for">Rater Has Known Individual For</label>
                        <input type="text" id="rater_known_for" name="rater_known_for" placeholder="years/months">
                    </div>
                </div>
            </div>

            <!-- Section 2: Subscale Performance -->
            <div class="form-section">
                <h2>Section 2: Subscale Performance</h2>

                <div class="instructions">
                    <h4>Instructions</h4>
                    <p>On a scale of 0 to 3, rate the following items in terms of how adequately the item describes the individual's behavior. Select the number that best describes observations of the person's typical behavior under ordinary circumstances.</p>
                    <p style="margin-top: 8px;"><strong>0</strong> = Not at all like the individual | <strong>1</strong> = Not much like the individual | <strong>2</strong> = Somewhat like the individual | <strong>3</strong> = Very much like the individual</p>
                </div>

                <?php foreach ($subscales as $si => $subscale): ?>
                <div class="subscale-section">
                    <div class="subscale-title"><?php echo $si + 1; ?>. <?php echo htmlspecialchars($subscale['name']); ?> (<?php echo $subscale['short']; ?>)</div>

                    <?php foreach ($subscale['questions'] as $qi => $item): ?>
                    <div class="item-row">
                        <div class="item-number"><?php echo $qi + 1; ?></div>
                        <div class="item-text"><?php echo htmlspecialchars($item); ?></div>
                        <div class="rating-options">
                            <label>
                                <input type="radio" name="<?php echo $subscale['id']; ?>_item_<?php echo $qi; ?>" value="0" checked>
                                <span>0</span>
                            </label>
                            <label>
                                <input type="radio" name="<?php echo $subscale['id']; ?>_item_<?php echo $qi; ?>" value="1">
                                <span>1</span>
                            </label>
                            <label>
                                <input type="radio" name="<?php echo $subscale['id']; ?>_item_<?php echo $qi; ?>" value="2">
                                <span>2</span>
                            </label>
                            <label>
                                <input type="radio" name="<?php echo $subscale['id']; ?>_item_<?php echo $qi; ?>" value="3">
                                <span>3</span>
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="subtotal-row">
                        <span>Subtotal <?php echo $subscale['short']; ?>:</span>
                        <span id="<?php echo $subscale['id']; ?>_subtotal">0</span>
                    </div>
                </div>
                <?php endforeach; ?>

            </div>

            <!-- Section 4: Mute Question -->
            <div class="form-section">
                <h2>Section 4: Mute Assessment</h2>
                <div class="mute-section">
                    <h3>Is the individual mute?</h3>
                    <p>If your answer is <strong>Yes</strong>, do not complete the next two subscales (Cognitive Style and Maladaptive Speech).</p>
                    <div class="mute-options">
                        <label>
                            <input type="radio" name="is_mute" value="yes" onchange="toggleMute(this)">
                            <span>Yes (Mute)</span>
                        </label>
                        <label>
                            <input type="radio" name="is_mute" value="no" checked onchange="toggleMute(this)">
                            <span>No (Not Mute)</span>
                        </label>
                    </div>
                </div>
            </div>

            <div style="padding: 20px 30px;">
                <button type="submit" class="submit-btn">Calculate Scores</button>
            </div>
        </form>
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
        document.querySelectorAll('input[type="radio"]').forEach(radio => {
            radio.addEventListener('change', calculateSubtotals);
        });
        calculateSubtotals();

        function toggleMute(radio) {
            const subscaleSections = document.querySelectorAll('.subscale-section');
            if (radio.value === 'yes') {
                subscaleSections[4].style.opacity = '0.4';
                subscaleSections[4].style.pointerEvents = 'none';
                subscaleSections[5].style.opacity = '0.4';
                subscaleSections[5].style.pointerEvents = 'none';
            } else {
                subscaleSections[4].style.opacity = '1';
                subscaleSections[4].style.pointerEvents = 'auto';
                subscaleSections[5].style.opacity = '1';
                subscaleSections[5].style.pointerEvents = 'auto';
            }
        }
    </script>
</body>
</html>
