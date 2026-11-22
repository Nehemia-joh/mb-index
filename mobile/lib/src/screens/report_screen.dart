import 'package:flutter/material.dart';

import '../api/models.dart';
import '../theme/app_theme.dart';

/// Scoring report after a save, and the read-only view of a stored record.
class ReportScreen extends StatelessWidget {
  const ReportScreen({
    super.key,
    required this.detail,
    required this.report,
    required this.recordId,
  });

  final AssessmentDetail detail;
  final Report? report;
  final int recordId;

  List<SubscaleResult> _results() {
    final fromReport = report?.subscaleResults;
    if (fromReport != null && fromReport.isNotEmpty) return fromReport;
    // stored record: rebuild rows from the score maps
    return [
      for (final entry in detail.scaled.entries)
        SubscaleResult(
          short: entry.key,
          name: subscaleNames[entry.key] ?? entry.key,
          rawScore: (detail.raw[entry.key] ?? 0).toInt(),
          scaledScore: entry.value.toInt(),
          percentileRank: '${detail.percentiles[entry.key] ?? 'n/a'}',
        ),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ai = detail.autismIndex;
    final severity = detail.severityLevel;
    final notAsd = severity == 'Not ASD';

    return Scaffold(
      appBar: AppBar(
        title: Text(recordId > 0 ? 'Record #$recordId' : 'Scoring report'),
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        children: [
          // — the Autism Index plate (blue surface, gold figure) —
          Container(
            padding: const EdgeInsets.all(18),
            decoration: BoxDecoration(
              color: Brand.brand,
              borderRadius: BorderRadius.circular(4),
              border: Border.all(color: Brand.brandDeep),
            ),
            child: Row(
              children: [
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('Autism Index',
                        style: TextStyle(color: Color(0xCCFFFFFF), fontSize: 13)),
                    const SizedBox(height: 6),
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 14, vertical: 4),
                      decoration: BoxDecoration(
                        color: Brand.gold,
                        borderRadius: BorderRadius.circular(3),
                      ),
                      child: Text('$ai',
                          style: const TextStyle(
                            color: Brand.brand,
                            fontSize: 42,
                            fontWeight: FontWeight.w700,
                            height: 1.1,
                          )),
                    ),
                  ],
                ),
                const SizedBox(width: 20),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('${detail.sumScaled}',
                          style: const TextStyle(
                            color: Brand.gold,
                            fontSize: 24,
                            fontWeight: FontWeight.w700,
                          )),
                      Text(
                          'Sum of scaled scores, ${report?.useSix ?? (detail.isMute ? 4 : 6)} subscales',
                          style: const TextStyle(
                              color: Color(0xCCFFFFFF), fontSize: 12)),
                      const SizedBox(height: 6),
                      Text('Percentile ${detail.compositePercentile}',
                          style: const TextStyle(
                              color: Color(0xE6FFFFFF), fontSize: 13)),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),

          // — interpretation —
          Card(
            margin: EdgeInsets.zero,
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const SectionHeading('Interpretation'),
                  if (notAsd)
                    Text(
                      'The probability of an autism spectrum disorder is '
                      '${detail.probability.toLowerCase()}: the profile does not '
                      'meet the DSM-5 criteria for ASD, so no severity level '
                      'is assigned.',
                      style: theme.textTheme.bodyMedium,
                    )
                  else ...[
                    Text(
                      'The probability of an autism spectrum disorder is '
                      '${detail.probability.toLowerCase()}, corresponding to '
                      'DSM-5 severity ${severity.toLowerCase()} '
                      '(${detail.severityDesc}).',
                      style: theme.textTheme.bodyMedium,
                    ),
                    const SizedBox(height: 8),
                    Builder(builder: (context) {
                      final c = _severityColor(severity);
                      return Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 12, vertical: 7),
                        decoration: BoxDecoration(
                          color: c.withOpacity(.12),
                          borderRadius: BorderRadius.circular(3),
                          border: Border.all(color: c.withOpacity(.45)),
                        ),
                        child: Text(severity,
                            style: TextStyle(
                                fontWeight: FontWeight.w700, color: c)),
                      );
                    }),
                  ],
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),

          // — subscale table —
          Card(
            margin: EdgeInsets.zero,
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const SectionHeading('Subscale performance'),
                  Table(
                    columnWidths: const {
                      0: FlexColumnWidth(3),
                      1: FlexColumnWidth(1),
                      2: FlexColumnWidth(1),
                      3: FlexColumnWidth(1),
                    },
                    children: [
                      TableRow(
                        children: [
                          _th('Subscale'),
                          _th('Raw', right: true),
                          _th('Scaled', right: true),
                          _th('Pct', right: true),
                        ],
                      ),
                      for (final row in _results())
                        TableRow(
                          children: [
                            Padding(
                              padding:
                                  const EdgeInsets.symmetric(vertical: 6),
                              child: Text(
                                '${row.name} (${row.short})',
                                style: theme.textTheme.bodySmall,
                              ),
                            ),
                            _td('${row.rawScore}', theme),
                            _td('${row.scaledScore ?? '–'}', theme),
                            _td(row.percentileRank, theme),
                          ],
                        ),
                    ],
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),

          // — identifying information —
          Card(
            margin: EdgeInsets.zero,
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const SectionHeading('Identifying information'),
                  _fact("Individual's name", detail.individualName, theme),
                  _fact('School', detail.school, theme),
                  _fact('Age', detail.age, theme),
                  _fact('Gender', detail.gender, theme),
                  if (detail.dateOfBirth != null)
                    _fact('Date of birth', detail.dateOfBirth!, theme),
                  if (detail.dateOfRating != null)
                    _fact('Date of rating', detail.dateOfRating!, theme),
                  _fact('Rater', '${detail.raterName} (${detail.raterTitle})',
                      theme),
                  _fact(
                      'Examiner',
                      '${detail.examinerName} (${detail.examinerTitle})',
                      theme),
                  if (detail.isMute)
                    _fact('Mute', 'Yes — CS and MS not completed', theme),
                ],
              ),
            ),
          ),
          const SizedBox(height: 20),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Done — back to records'),
          ),
        ],
      ),
    );
  }

  /// Severity colours from the web design system: Level 2 = orange,
  /// Level 3 = red-deep, everything else = the "ok" green (Level 1).
  static Color _severityColor(String severity) => switch (severity) {
        'Level 2' => Brand.level2,
        'Level 3' => Brand.level3,
        'Not ASD' => Brand.muted,
        _ => Brand.ok,
      };

  Widget _th(String text, {bool right = false}) => Padding(
        padding: const EdgeInsets.only(bottom: 6),
        child: Text(
          text,
          textAlign: right ? TextAlign.right : TextAlign.left,
          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12),
        ),
      );

  Widget _td(String text, ThemeData theme) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Text(
          text,
          textAlign: TextAlign.right,
          style: theme.textTheme.bodySmall,
        ),
      );

  Widget _fact(String k, String v, ThemeData theme) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: 140,
              child: Text(k,
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: theme.colorScheme.outline)),
            ),
            Expanded(
              child: Text(v.isEmpty ? '—' : v,
                  style: theme.textTheme.bodyMedium),
            ),
          ],
        ),
      );
}
