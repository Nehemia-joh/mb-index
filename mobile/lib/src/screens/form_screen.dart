import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../api/models.dart';
import '../state/session.dart';
import 'report_screen.dart';

/// The GARS-3 rating form: demographics + six subscales, rendered from
/// GET /meta/form so item wordings always match the server.
class FormScreen extends StatefulWidget {
  const FormScreen({super.key});

  @override
  State<FormScreen> createState() => _FormScreenState();
}

class _FormScreenState extends State<FormScreen> {
  FormMeta? _meta;
  String? _loadError;
  bool _submitting = false;
  String? _submitError;

  final _fields = <String, TextEditingController>{};
  String _gender = '';
  DateTime? _dob;
  DateTime? _dateOfRating;
  bool _isMute = false;

  /// '${subscaleId}_item_$i' -> 0..3 (server default is 0, like the web form)
  final _items = <String, int>{};

  static const _textFields = [
    'individual_name',
    'school',
    'rater_name',
    'rater_title',
    'age',
    'examiner_name',
    'examiner_title',
    'grade',
    'rater_known_for',
  ];

  @override
  void initState() {
    super.initState();
    for (final f in _textFields) {
      _fields[f] = TextEditingController();
    }
    _loadMeta();
  }

  @override
  void dispose() {
    for (final c in _fields.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _loadMeta() async {
    final session = context.read<Session>();
    try {
      final meta = await session.client.metaForm();
      if (!mounted) return;
      setState(() {
        _meta = meta;
        if (meta.genderOptions.isNotEmpty) _gender = meta.genderOptions.first;
        for (final sub in meta.subscales) {
          for (var i = 0; i < sub.itemCount; i++) {
            _items.putIfAbsent('${sub.id}_item_$i', () => 0);
          }
        }
      });
    } on ApiException catch (e) {
      if (e.isAuth && mounted) {
        await session.sessionExpired(e.message);
        return;
      }
      if (mounted) setState(() => _loadError = e.message);
    } catch (_) {
      if (mounted) setState(() => _loadError = 'Could not load the form.');
    }
  }

  String _fmtDate(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<void> _pickDate(bool isDob) async {
    final now = DateTime.now();
    final initial = isDob
        ? (_dob ?? DateTime(now.year - 7, now.month, now.day))
        : (_dateOfRating ?? now);
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(1930),
      lastDate: now,
    );
    if (picked == null) return;
    setState(() {
      if (isDob) {
        _dob = picked;
      } else {
        _dateOfRating = picked;
      }
    });
  }

  Map<String, dynamic> _buildPayload() {
    final payload = <String, dynamic>{
      for (final f in _textFields) f: _fields[f]!.text.trim(),
      'gender': _gender,
      'is_mute': _isMute ? 'yes' : 'no',
      if (_dob != null) 'date_of_birth': _fmtDate(_dob!),
      if (_dateOfRating != null) 'date_of_rating': _fmtDate(_dateOfRating!),
      ..._items,
    };
    return payload;
  }

  Future<void> _submit() async {
    final meta = _meta;
    if (meta == null || _submitting) return;
    final payload = _buildPayload();

    // client-side pass over the server's required list
    final missing = <String>[];
    for (final field in meta.requiredFields) {
      final value = '${payload[field] ?? ''}'.trim();
      if (value.isEmpty) {
        missing.add(meta.requiredLabels[field] ?? field);
      }
    }
    if (missing.isNotEmpty) {
      setState(() =>
          _submitError = 'Still needed: ${missing.join(', ')}.');
      return;
    }

    setState(() {
      _submitting = true;
      _submitError = null;
    });
    final session = context.read<Session>();
    try {
      final result = await session.client.createAssessment(payload);
      if (!mounted) return;
      await Navigator.of(context).pushReplacement<bool, bool>(
        MaterialPageRoute(
          builder: (_) => ReportScreen(
            detail: result.record,
            report: result.report,
            recordId: result.id,
          ),
        ),
      );
    } on ApiException catch (e) {
      if (e.isAuth && mounted) {
        await session.sessionExpired(e.message);
        return;
      }
      if (mounted) {
        setState(() => _submitError =
            e.statusCode == 422 ? e.message : 'Not saved: ${e.message}');
      }
    } catch (_) {
      if (mounted) setState(() => _submitError = 'Not saved — unexpected error.');
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  int _subtotal(Subscale sub) {
    var sum = 0;
    for (var i = 0; i < sub.itemCount; i++) {
      sum += _items['${sub.id}_item_$i'] ?? 0;
    }
    return sum;
  }

  bool _mutedOut(Subscale sub) =>
      _isMute && (sub.short == 'CS' || sub.short == 'MS');

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final meta = _meta;

    return Scaffold(
      appBar: AppBar(title: const Text('New assessment')),
      body: meta == null
          ? _buildLoading(theme)
          : ListView(
              padding: const EdgeInsets.fromLTRB(12, 8, 12, 24),
              children: [
                if (_submitError != null) ...[
                  MaterialBanner(
                    backgroundColor: theme.colorScheme.errorContainer,
                    content: Text(_submitError!,
                        style: TextStyle(
                            color: theme.colorScheme.onErrorContainer)),
                    actions: const [SizedBox.shrink()],
                  ),
                  const SizedBox(height: 8),
                ],
                _demographicsCard(theme, meta),
                const SizedBox(height: 12),
                _ratingCard(theme, meta),
                const SizedBox(height: 88),
              ],
            ),
      bottomNavigationBar: meta == null
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
                child: FilledButton.icon(
                  onPressed: _submitting ? null : _submit,
                  icon: _submitting
                      ? const SizedBox(
                          height: 18,
                          width: 18,
                          child: CircularProgressIndicator(strokeWidth: 2))
                      : const Icon(Icons.calculate_outlined),
                  label: Text(
                      _submitting ? 'Scoring…' : 'Score and save record'),
                ),
              ),
            ),
    );
  }

  Widget _buildLoading(ThemeData theme) {
    if (_loadError != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(_loadError!, textAlign: TextAlign.center),
              const SizedBox(height: 12),
              OutlinedButton(
                  onPressed: _loadMeta, child: const Text('Try again')),
            ],
          ),
        ),
      );
    }
    return const Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          CircularProgressIndicator(),
          SizedBox(height: 12),
          Text('Loading the rating form…'),
        ],
      ),
    );
  }

  Widget _demographicsCard(ThemeData theme, FormMeta meta) {
    InputDecoration dec(String label) => InputDecoration(labelText: label);
    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('1 · Identifying information',
                style: theme.textTheme.titleMedium
                    ?.copyWith(fontWeight: FontWeight.bold)),
            const SizedBox(height: 6),
            Text('Fields marked * are required before the record can be saved.',
                style: theme.textTheme.bodySmall),
            const SizedBox(height: 14),
            TextField(
                controller: _fields['individual_name'],
                decoration: dec("Individual's name *")),
            const SizedBox(height: 12),
            TextField(
                controller: _fields['school'], decoration: dec('School *')),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: TextField(
                      controller: _fields['age'],
                      decoration: dec('Age * (e.g. 7y 4m)')),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: DropdownButtonFormField<String>(
                    value: _gender.isEmpty ? null : _gender,
                    decoration: dec('Gender *'),
                    items: [
                      for (final g in meta.genderOptions)
                        DropdownMenuItem(value: g, child: Text(g)),
                    ],
                    onChanged: (v) =>
                        setState(() => _gender = v ?? _gender),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: TextFormField(
                    readOnly: true,
                    controller: TextEditingController(
                        text: _dob == null ? '' : _fmtDate(_dob!)),
                    decoration: const InputDecoration(
                      labelText: 'Date of birth *',
                      suffixIcon: Icon(Icons.calendar_today, size: 18),
                      errorText: null,
                    ),
                    onTap: () => _pickDate(true),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: TextFormField(
                    readOnly: true,
                    controller: TextEditingController(
                        text: _dateOfRating == null
                            ? ''
                            : _fmtDate(_dateOfRating!)),
                    decoration: const InputDecoration(
                      labelText: 'Date of rating *',
                      suffixIcon: Icon(Icons.calendar_today, size: 18),
                    ),
                    onTap: () => _pickDate(false),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            TextField(
                controller: _fields['rater_name'],
                decoration: dec("Rater's name *")),
            const SizedBox(height: 12),
            TextField(
                controller: _fields['rater_title'],
                decoration: dec("Rater's title *")),
            const SizedBox(height: 12),
            TextField(
                controller: _fields['examiner_name'],
                decoration: dec("Examiner's name *")),
            const SizedBox(height: 12),
            TextField(
                controller: _fields['examiner_title'],
                decoration: dec("Examiner's title *")),
            const SizedBox(height: 12),
            TextField(
                controller: _fields['grade'], decoration: dec('Grade')),
            const SizedBox(height: 12),
            TextField(
                controller: _fields['rater_known_for'],
                decoration: dec('Rater has known the individual for')),
            const SizedBox(height: 16),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: const Text('The individual is mute'),
              subtitle: const Text(
                  'Cognitive Style and Maladaptive Speech will not be completed.'),
              value: _isMute,
              onChanged: (v) => setState(() => _isMute = v),
            ),
          ],
        ),
      ),
    );
  }

  Widget _ratingCard(ThemeData theme, FormMeta meta) {
    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 14, 14, 4),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('2 · Rating form',
                style: theme.textTheme.titleMedium
                    ?.copyWith(fontWeight: FontWeight.bold)),
            const SizedBox(height: 6),
            const Wrap(
              spacing: 12,
              runSpacing: 4,
              children: [
                _Legend('0', 'Not at all'),
                _Legend('1', 'Not much'),
                _Legend('2', 'Somewhat'),
                _Legend('3', 'Very much'),
              ],
            ),
            const SizedBox(height: 4),
            for (final sub in meta.subscales)
              _subscaleTile(theme, sub),
          ],
        ),
      ),
    );
  }

  Widget _subscaleTile(ThemeData theme, Subscale sub) {
    final mutedOut = _mutedOut(sub);
    return ExpansionTile(
      enabled: !mutedOut,
      tilePadding: EdgeInsets.zero,
      title: Row(
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
            decoration: BoxDecoration(
              color: mutedOut
                  ? theme.colorScheme.surfaceContainerHighest
                  : theme.colorScheme.primaryContainer,
              borderRadius: BorderRadius.circular(4),
            ),
            child: Text(sub.short,
                style: TextStyle(
                    fontWeight: FontWeight.bold,
                    color: theme.colorScheme.onPrimaryContainer)),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(sub.name,
                style: theme.textTheme.titleSmall
                    ?.copyWith(fontWeight: FontWeight.w600)),
          ),
          if (mutedOut)
            const Text('mute', style: TextStyle(fontSize: 12))
          else
            Text('${_subtotal(sub)} / ${sub.itemCount * 3}',
                style: theme.textTheme.labelMedium),
        ],
      ),
      children: [
        for (var i = 0; i < sub.itemCount; i++) _itemRow(theme, sub, i),
        const SizedBox(height: 8),
      ],
    );
  }

  Widget _itemRow(ThemeData theme, Subscale sub, int index) {
    final key = '${sub.id}_item_$index';
    final value = _items[key] ?? 0;
    final mutedOut = _mutedOut(sub);
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              CircleAvatar(
                radius: 10,
                backgroundColor: theme.colorScheme.surfaceContainerHighest,
                child: Text('${index + 1}',
                    style: const TextStyle(fontSize: 11)),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(sub.questions[index],
                    style: theme.textTheme.bodyMedium),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Padding(
            padding: const EdgeInsets.only(left: 28),
            child: SegmentedButton<int>(
              showSelectedIcon: false,
              style: const ButtonStyle(
                visualDensity: VisualDensity.compact,
                tapTargetSize: MaterialTapTargetSize.shrinkWrap,
              ),
              segments: const [
                ButtonSegment(value: 0, label: Text('0')),
                ButtonSegment(value: 1, label: Text('1')),
                ButtonSegment(value: 2, label: Text('2')),
                ButtonSegment(value: 3, label: Text('3')),
              ],
              selected: {value},
              onSelectionChanged: mutedOut
                  ? null
                  : (selection) =>
                      setState(() => _items[key] = selection.first),
            ),
          ),
        ],
      ),
    );
  }
}

class _Legend extends StatelessWidget {
  const _Legend(this.mark, this.label);

  final String mark;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Row(mainAxisSize: MainAxisSize.min, children: [
      Container(
        width: 18,
        height: 18,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: Theme.of(context).colorScheme.primaryContainer,
          borderRadius: BorderRadius.circular(4),
        ),
        child: Text(mark,
            style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
      ),
      const SizedBox(width: 4),
      Text(label, style: Theme.of(context).textTheme.bodySmall),
    ]);
  }
}
