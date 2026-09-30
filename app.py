"""
Gilliam Autism Rating Scale - Third Edition (GARS-3)
Flask Scoring Application

This application implements the GARS-3 scoring algorithm:
- Section 2: Raw scores from item ratings (0-3) for 6 subscales
- Table A: Converts raw scores to percentile ranks and scaled scores
- Section 3: Sums scaled scores (4 or 6 subscales) and uses Table B
  to get percentile rank and Autism Index
- Section 4: Interpretation based on Autism Index
"""

from flask import Flask, render_template, request, redirect, url_for

app = Flask(__name__)

# ============================================================================
# SUBSCALE DEFINITIONS
# ============================================================================

SUBSCALES = [
    {
        "id": "rb",
        "name": "Restricted/Repetitive Behaviors",
        "short": "RB",
        "questions": [
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
    },
    {
        "id": "si",
        "name": "Social Interaction",
        "short": "SI",
        "questions": [
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
    },
    {
        "id": "sc",
        "name": "Social Communication",
        "short": "SC",
        "questions": [
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
    },
    {
        "id": "er",
        "name": "Emotional Responses",
        "short": "ER",
        "questions": [
            "Needs an excessive amount of reassurance if things are changed or go wrong.",
            "Becomes frustrated quickly when he or she cannot do something.",
            "Temper tantrums when frustrated.",
            "Becomes upset when routines are changed.",
            "Responds negatively when given commands, requests, or directions.",
            "Has extreme reactions (e.g., cries, screams, tantrums) in response to loud, unexpected noise.",
            "Temper tantrums when doesn't get his or her way.",
            "Temper tantrums when told to stop doing something he or she enjoys doing.",
        ],
    },
    {
        "id": "cs",
        "name": "Cognitive Style",
        "short": "CS",
        "questions": [
            "Uses exceptionally precise speech.",
            "Attaches very concrete meanings to words.",
            "Talks about a single subject excessively.",
            "Displays superior knowledge or skill in specific subjects.",
            "Displays excellent memory.",
            "Shows an intense, obsessive interest in specific intellectual subjects.",
            "Makes naive remarks (unaware of reaction produced in others).",
        ],
    },
    {
        "id": "ms",
        "name": "Maladaptive Speech",
        "short": "MS",
        "questions": [
            "Repeats (echoes) words or phrases verbally or with signs.",
            "Repeats words out of context (repeats words or phrases heard at an earlier time).",
            "Speaks (or signs) with flat tone, affect.",
            "Uses \"yes\" and \"no\" inappropriately. Says \"yes\" when asked if he or she wants an aversive stimulus or says \"no\" when asked if he or she wants a favorite toy or treat.",
            "Uses \"he\" or \"she\" instead of \"I\" when referring to self.",
            "Speech is abnormal in tone, volume, or rate.",
            "Utters idiosyncratic words or phrases that have no meaning to others.",
        ],
    },
]

# ============================================================================
# TABLE A: Raw Score Ranges -> Scaled Score & Percentile Rank
# ============================================================================
# Each entry: (scaled_score, percentile_rank, {subscale_short: (min, max) or None})
# None means no raw score in that subscale maps to this scaled score.

TABLE_A = [
    # SS, %ile, RB, SI, SC, ER, CS, MS
    (1, "<1", None, None, None, None, None, None),
    (2, "<1", None, None, (0, 1), None, None, None),
    (3, "1", None, (0, 0), (2, 4), (0, 1), None, None),
    (3, "2", (0, 3), (1, 4), (3, 5), (2, 4), None, None),
    (5, "3", (4, 6), (5, 8), (9, 11), (5, 6), (0, 0), (0, 0)),
    (6, "9", (7, 9), (9, 12), (12, 13), (7, 8), (1, 1), (1, 2)),
    (7, "16", (10, 13), (13, 15), (14, 16), (9, 10), (0, 3), (3, 4)),
    (8, "25", (14, 16), (16, 19), (17, 18), (11, 12), (4, 8), (5, 5)),
    (9, "32", (17, 19), (20, 23), (19, 21), (13, 14), (6, 9), (6, 7)),
    (10, "50", (20, 22), (24, 27), (22, 23), (15, 16), (9, 10), (8, 9)),
    (11, "63", (23, 26), (28, 30), (24, 25), (17, 18), (11, 13), (10, 11)),
    (12, "75", (27, 29), (31, 34), (26, 27), (19, 20), (14, 16), (12, 13)),
    (13, "84", (30, 32), (35, 38), None, (21, 22), (16, 17), (14, 15)),
    (14, "91", (33, 36), (39, 42), None, (23, 24), (18, 19), (16, 18)),
    (15, "95", (37, 39), None, None, None, (20, 21), (17, 18)),
    (16, "98", None, None, None, None, (19, 20), (19, 20)),
    (17, "99", None, None, None, None, (21, 21), (21, 21)),
    (18, ">99", None, None, None, None, None, (18, 18)),
    (19, ">99", None, None, None, None, None, (19, 19)),
    (20, ">99", None, None, None, None, None, (20, 20)),
]

# ============================================================================
# TABLE B: Sum of Scaled Scores -> Percentile Rank & Autism Index
# ============================================================================
# Each entry: (percentile_rank, sum_4_subscales, sum_6_subscales, autism_index)
# None means no value for that column at this percentile rank.

TABLE_B = [
    (">99", None, (87, 87), 140),
    (">99", None, (86, 86), 139),
    (">99", None, (85, 85), 137),
    (">99", None, (84, 84), 136),
    ("99", None, (83, 83), 134),
    ("99", None, (82, 82), 133),
    ("99", None, (81, 81), 131),
    ("98", None, (80, 80), 130),
    ("98", None, (79, 79), 128),
    ("97", None, (78, 78), 127),
    ("96", (55, 55), None, 126),
    ("95", (54, 54), (77, 77), 125),
    ("95", None, (76, 76), 124),
    ("94", (53, 53), None, 123),
    ("93", None, (75, 75), 122),
    ("92", (52, 52), (74, 74), 121),
    ("91", None, (73, 73), 120),
    ("90", (51, 51), None, 119),
    ("89", (50, 50), (72, 72), 118),
    ("87", None, (71, 71), 117),
    ("86", (49, 49), None, 116),
    ("84", None, (70, 70), 115),
    ("82", (48, 48), (69, 69), 114),
    ("79", (47, 47), (68, 68), 112),
    ("77", (46, 46), (67, 67), 111),
    ("73", (45, 45), (66, 66), 109),
    ("70", None, (65, 65), 108),
    ("68", (44, 44), None, 107),
    ("65", (43, 43), (64, 64), 106),
    ("63", None, (63, 63), 105),
    ("61", (42, 42), None, 104),
    ("58", None, (62, 62), 103),
    ("55", (41, 41), (61, 61), 102),
    ("50", (40, 40), (60, 60), 100),
    ("47", (39, 39), (59, 59), 99),
    ("42", (38, 38), (58, 58), 97),
    ("39", None, (57, 57), 96),
    ("37", (37, 37), None, 95),
    ("35", None, (56, 56), 94),
    ("32", (36, 36), (55, 55), 93),
    ("29", (35, 35), (54, 54), 92),
    ("25", (34, 34), (53, 53), 90),
    ("23", None, (52, 52), 89),
    ("21", (33, 33), None, 88),
    ("18", (32, 32), (50, 50), 86),
    ("16", (31, 31), None, 85),
    ("14", None, (49, 49), 84),
    ("13", (30, 30), (48, 48), 83),
    ("10", (29, 29), (47, 47), 81),
    ("9", None, (46, 46), 80),
    ("8", (28, 28), None, 79),
    ("7", (27, 27), (45, 45), 78),
    ("6", None, (44, 44), 77),
    ("5", (26, 26), None, 76),
    ("5", None, (43, 43), 75),
    ("4", (25, 25), (42, 42), 74),
    ("3", (24, 24), None, 73),
    ("3", None, (41, 41), 72),
    ("3", (23, 23), (40, 40), 71),
    ("2", (22, 22), (39, 39), 69),
    ("1", None, (38, 38), 68),
    ("1", (21, 21), None, 67),
    ("1", (20, 20), (37, 37), 66),
    ("1", None, (36, 36), 65),
    ("<1", (19, 19), None, 64),
    ("<1", None, (35, 35), 63),
    ("<1", (18, 18), (34, 34), 62),
    ("<1", None, (33, 33), 61),
    ("<1", (17, 17), None, 60),
    ("<1", (16, 16), (32, 32), 59),
    ("<1", None, (31, 31), 58),
    ("<1", (15, 15), None, 57),
    ("<1", None, (30, 30), 56),
    ("<1", (14, 14), (29, 29), 55),
    ("<1", (13, 13), (28, 28), 53),
    ("<1", (12, 12), (27, 27), 52),
    ("<1", (12, 12), (26, 26), 50),
    ("<1", None, (25, 25), 49),
    ("<1", None, (24, 24), 47),
    ("<1", None, (23, 23), 46),
    ("<1", None, (22, 22), 44),
    ("<1", None, (21, 21), 43),
]


# ============================================================================
# SCORING FUNCTIONS
# ============================================================================


def get_scaled_score_and_percentile(raw_score, subscale_short):
    """
    Look up Table A to find the scaled score and percentile rank
    for a given raw score in a given subscale.

    Returns: (scaled_score, percentile_rank) or (None, None) if not found.
    """
    for entry in TABLE_A:
        ss = entry[0]
        pct = entry[1]
        ranges = entry[2:]  # RB, SI, SC, ER, CS, MS

        subscale_index = {"RB": 0, "SI": 1, "SC": 2, "ER": 3, "CS": 4, "MS": 5}
        idx = subscale_index.get(subscale_short)
        if idx is None:
            continue

        range_tuple = ranges[idx]
        if range_tuple is not None:
            min_val, max_val = range_tuple
            if min_val <= raw_score <= max_val:
                return ss, pct

    return None, None


def get_composite_results(sum_scaled_scores, use_six_subscales):
    """
    Look up Table B to find the percentile rank and Autism Index
    for a given sum of scaled scores.

    Returns: (percentile_rank, autism_index) or (None, None) if not found.
    """
    for entry in TABLE_B:
        pct = entry[0]
        sum_4_range = entry[1]
        sum_6_range = entry[2]
        autism_index = entry[3]

        if use_six_subscales:
            if sum_6_range is not None:
                min_val, max_val = sum_6_range
                if min_val <= sum_scaled_scores <= max_val:
                    return pct, autism_index
        else:
            if sum_4_range is not None:
                min_val, max_val = sum_4_range
                if min_val <= sum_scaled_scores <= max_val:
                    return pct, autism_index

    return None, None


def get_interpretation(autism_index):
    """
    Section 4: Interpretation Guide based on Autism Index.

    Returns: (probability_of_asd, dsm5_severity, descriptor)
    """
    if autism_index is None:
        return None, None, None

    if autism_index <= 54:
        return "Unlikely", "Not ASD", "Not ASD"
    elif 55 <= autism_index <= 70:
        return "Probable", "Level 1", "Minimal Support Required"
    elif 71 <= autism_index <= 100:
        return "Very Likely", "Level 2", "Requiring Substantial Support"
    else:  # >= 101
        return "Very Likely", "Level 3", "Requiring Very Substantial Support"


# ============================================================================
# FLASK ROUTES
# ============================================================================


@app.route("/")
def index():
    """Display the GARS-3 rating form."""
    return render_template("index.html", subscales=SUBSCALES)


@app.route("/score", methods=["POST"])
def score():
    """Process the rating form and display results."""
    # Get identifying information
    individual_name = request.form.get("individual_name", "")
    school = request.form.get("school", "")
    rater_name = request.form.get("rater_name", "")
    rater_title = request.form.get("rater_title", "")
    date_of_birth = request.form.get("date_of_birth", "")
    age = request.form.get("age", "")
    gender = request.form.get("gender", "")
    grade = request.form.get("grade", "")
    examiner_name = request.form.get("examiner_name", "")
    examiner_title = request.form.get("examiner_title", "")
    date_of_rating = request.form.get("date_of_rating", "")
    rater_known_for = request.form.get("rater_known_for", "")

    # Check if individual is mute
    is_mute = request.form.get("is_mute", "no") == "yes"

    # Calculate raw scores for each subscale
    subscale_results = []
    for subscale in SUBSCALES:
        raw_score = 0
        item_scores = []
        for i, item in enumerate(subscale["questions"]):
            field_name = f"{subscale['id']}_item_{i}"
            score = request.form.get(field_name, "0")
            try:
                score = int(score)
            except (ValueError, TypeError):
                score = 0
            item_scores.append(score)
            raw_score += score

        # Get scaled score and percentile from Table A
        scaled_score, percentile_rank = get_scaled_score_and_percentile(
            raw_score, subscale["short"]
        )

        subscale_results.append(
            {
                "id": subscale["id"],
                "name": subscale["name"],
                "short": subscale["short"],
                "raw_score": raw_score,
                "scaled_score": scaled_score,
                "percentile_rank": percentile_rank,
                "item_scores": item_scores,
            }
        )

    # Determine which subscales to include in composite
    if is_mute:
        # If mute, only use first 4 subscales (RB, SI, SC, ER)
        included_subscales = subscale_results[:4]
        use_six = False
    else:
        # Use all 6 subscales
        included_subscales = subscale_results
        use_six = True

    # Calculate sum of scaled scores
    sum_scaled = sum(s["scaled_score"] for s in included_subscales if s["scaled_score"] is not None)

    # Get composite results from Table B
    composite_percentile, autism_index = get_composite_results(sum_scaled, use_six)

    # Get interpretation
    probability, severity, descriptor = get_interpretation(autism_index)

    return render_template(
        "results.html",
        individual_name=individual_name,
        school=school,
        rater_name=rater_name,
        rater_title=rater_title,
        date_of_birth=date_of_birth,
        age=age,
        gender=gender,
        grade=grade,
        examiner_name=examiner_name,
        examiner_title=examiner_title,
        date_of_rating=date_of_rating,
        rater_known_for=rater_known_for,
        is_mute=is_mute,
        subscale_results=subscale_results,
        included_subscales=included_subscales,
        use_six=use_six,
        sum_scaled=sum_scaled,
        composite_percentile=composite_percentile,
        autism_index=autism_index,
        probability=probability,
        severity=severity,
        descriptor=descriptor,
    )


if __name__ == "__main__":
    app.run(debug=True, host="0.0.0.0", port=5000)
