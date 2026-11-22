import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api/models.dart';
import '../state/session.dart';
import '../theme/app_theme.dart';
import 'shell_bits.dart';

/// The role tab: therapist → Dashboard, admin → Admin, it_admin → IT admin.
///
/// Built entirely from the existing API (`GET /auth/me` + the record list
/// totals), so no new endpoints were needed for it.
class RoleScreen extends StatefulWidget {
  const RoleScreen({
    super.key,
    required this.role,
    required this.onNewAssessment,
  });

  final String role;
  final VoidCallback onNewAssessment;

  @override
  State<RoleScreen> createState() => _RoleScreenState();
}

class _RoleScreenState extends State<RoleScreen> {
  bool _loading = true;
  String? _error;
  int _total = 0;
  String _scope = 'own';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    final session = context.read<Session>();
    try {
      final result = await session.client.listAssessments(page: 1, perPage: 1);
      if (!mounted) return;
      setState(() {
        _total = result.total;
        _scope = result.scope;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (e.isAuth && mounted) {
        await session.sessionExpired(e.message);
        return;
      }
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = 'Could not load the overview.');
    }
  }

  /// The web app root behind the API: …/api/v1 → …/
  String get _webRoot {
    final base = context.read<Session>().client.baseUrl.trim();
    return base.replaceFirst(RegExp(r'/api/v1/?$'), '');
  }

  Future<void> _openConsole() async {
    final uri = Uri.tryParse(_webRoot);
    if (uri == null || !uri.hasScheme) return;
    try {
      final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
      if (!ok && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Could not open $_webRoot')),
        );
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Could not open $_webRoot')),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final user = session.user;
    final title = roleTabLabel(widget.role);

    return Scaffold(
      appBar: AppBar(
        title: const BrandWordmark(),
        actions: [
          IconButton(
            tooltip: 'Sign out',
            onPressed: () => confirmLogout(context),
            icon: const Icon(Icons.logout),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
          children: [
            Text(title, style: Brand.sectionStyle),
            Container(
              width: 48,
              height: 2,
              color: Brand.gold,
              margin: const EdgeInsets.only(top: 7, bottom: 16),
            ),

            // — account —
            const SectionHeading('Account'),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      user?.fullName ?? user?.username ?? '',
                      style: const TextStyle(
                        fontFamily: Brand.display,
                        fontSize: 22,
                        fontWeight: FontWeight.w600,
                        color: Brand.brand,
                      ),
                    ),
                    const SizedBox(height: 10),
                    _fact('Username', user?.username ?? '—'),
                    _fact('Base role', user?.role ?? '—'),
                    _fact(
                      'Effective roles',
                      (user?.roles ?? const []).isEmpty
                          ? '—'
                          : (user!.roles).join(', '),
                    ),
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        for (final r in user?.roles ?? const <String>[]) ...[
                          RoleBadge(r),
                          const SizedBox(width: 8),
                        ],
                      ],
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),

            // — overview —
            const SectionHeading('Overview'),
            if (_loading)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 24),
                child: Center(child: CircularProgressIndicator()),
              )
            else if (_error != null)
              NoticeBar(_error!, kind: NoticeKind.error)
            else
              Row(
                children: [
                  Expanded(
                    child: StatTile(
                      value: '$_total',
                      label: _scope == 'all'
                          ? 'Records in the system'
                          : 'Records you created',
                    ),
                  ),
                  Expanded(
                    child: StatTile(
                      value: _scope == 'all' ? 'All' : 'Own',
                      label: 'Data scope for your role',
                    ),
                  ),
                ],
              ),
            const SizedBox(height: 16),

            // — actions —
            const SectionHeading('Actions'),
            FilledButton.icon(
              onPressed: widget.onNewAssessment,
              icon: const Icon(Icons.note_add_outlined),
              label: const Text('New assessment'),
            ),
            if (widget.role != 'therapist') ...[
              const SizedBox(height: 10),
              OutlinedButton.icon(
                onPressed: _openConsole,
                icon: const Icon(Icons.open_in_new, size: 18),
                label: const Text('Open admin console'),
              ),
              const SizedBox(height: 8),
              Text(
                _webRoot,
                style: const TextStyle(color: Brand.muted, fontSize: 12),
              ),
            ],
            const SizedBox(height: 20),
            OutlinedButton.icon(
              onPressed: () => confirmLogout(context),
              icon: const Icon(Icons.logout, size: 18),
              label: const Text('Sign out'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _fact(String k, String v) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: 130,
              child: Text(k,
                  style:
                      const TextStyle(color: Brand.muted, fontSize: 13)),
            ),
            Expanded(
              child: Text(
                v.isEmpty ? '—' : v,
                style: const TextStyle(
                    fontSize: 14, fontWeight: FontWeight.w500),
              ),
            ),
          ],
        ),
      );
}
