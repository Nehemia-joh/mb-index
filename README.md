# GARS-3 Flask Scoring Application

A Flask web application for scoring the Gilliam Autism Rating Scale - Third Edition (GARS-3).

## Features

- **Section 1**: Identifying information form
- **Section 2**: Rate all 57 items across 6 subscales (0-3 scale)
  - Restricted/Repetitive Behaviors (RB) - 13 items
  - Social Interaction (SI) - 13 items
  - Social Communication (SC) - 9 items
  - Emotional Responses (ER) - 8 items
  - Cognitive Style (CS) - 7 items
  - Maladaptive Speech (MS) - 7 items
- **Mute Feature**: If the individual is mute, CS and MS subscales are excluded and only 4 subscales are used for composite scoring
- **Table A**: Automatic conversion of raw scores to percentile ranks and scaled scores
- **Section 3**: Composite scoring (sum of scaled scores) with Table B lookup for percentile rank and Autism Index
- **Section 4**: Interpretation guide based on Autism Index (Probability of ASD, DSM-5 Severity Level)

## Installation

```bash
pip install -r requirements.txt
```

## Usage

```bash
cd Gilliam
python app.py
```

Then open your browser and navigate to `http://localhost:5000`

## Scoring Algorithm

1. **Raw Scores**: Sum of item ratings (0-3) for each subscale
2. **Table A**: Raw scores are converted to scaled scores and percentile ranks
3. **Composite**: Sum of scaled scores (4 or 6 subscales depending on mute status)
4. **Table B**: Sum of scaled scores is converted to percentile rank and Autism Index
5. **Interpretation**: Autism Index determines probability of ASD and DSM-5 severity level

## File Structure

```
Gilliam/
├── app.py                 # Main Flask application with scoring logic
├── requirements.txt       # Python dependencies
├── templates/
│   ├── index.html        # Rating form (Section 1 & 2)
│   └── results.html      # Results page (Section 2, 3 & 4)
├── static/               # Static files (CSS, JS)
├── Table A.pdf           # Original Table A reference
└── Gilliam Autism Rating Scale.pdf  # Original GARS-3 form
```
