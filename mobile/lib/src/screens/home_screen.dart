import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../api/models.dart';
import '../state/session.dart';
import '../theme/app_theme.dart';
import 'form_screen.dart';
import 'report_screen.dart';
import 'role_screen.dart';
import 'shell_bits.dart';

/// App shell: the bottom tab bar (New · Records · role tab) with the three
/// tab bodies kept alive in an [IndexedStack].
///
/// Tab contents follow the web navigation in `gars_nav_links()`: everyone
/// gets New assessment + Records, and the role tab is therapist → Dashboard,
/// admin → Admin, it_admin → IT admin.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  final _recordsKey = GlobalKey<RecordsTabState>();

  /// Records is the landing tab; the New tab mounts its form on first use.
  /// Tab bodies are listed in the same order as [NavigationDestination]s.
  int _index = 1;
  bool _formMounted = false;
  Key _formKey = const ValueKey('assessment-form');

  void _select(int index) {
    setState(() {
      _index = index;
      if (index == 0) _formMounted = true; // the New tab
    });
  }

  /// Called by the form after a record is saved: land on Records, refreshed,
  /// and hand the New tab a blank form for the next assessment.
  void _onRecordSaved() {
    setState(() {
      _index = 1; // Records
      _formMounted = true;
      _formKey = UniqueKey();
    });
    _recordsKey.currentState?.refresh();
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final user = session.user;
    final roleTab = roleTabFor((user?.roles ?? const []).toSet());

    final destinations = <NavigationDestination>[
      const NavigationDestination(
        icon: Icon(Icons.note_add_outlined),
        selectedIcon: Icon(Icons.note_add),
        label: 'New',
      ),
      const NavigationDestination(
        icon: Icon(Icons.fact_check_outlined),
        selectedIcon: Icon(Icons.fact_check),
        label: 'Records',
      ),
      if (roleTab != null)
        NavigationDestination(
          icon: Icon(switch (roleTab) {
            'therapist' => Icons.dashboard_outlined,
            'it_admin' => Icons.admin_panel_settings_outlined,
            _ => Icons.settings_outlined,
          }),
          selectedIcon: Icon(switch (roleTab) {
            'therapist' => Icons.dashboard,
            'it_admin' => Icons.admin_panel_settings,
            _ => Icons.settings,
          }),
          label: roleTabLabel(roleTab),
        ),
    ];

    final tabs = <Widget>[
      // 0 · New assessment
      _formMounted
          ? FormScreen(key: _formKey, onRecordSaved: _onRecordSaved)
          : const SizedBox.shrink(),
      // 1 · Records
      const RecordsTab(),
      // 2 · role tab (therapist dashboard / admin / IT admin)
      if (roleTab != null)
        RoleScreen(
          role: roleTab,
          onNewAssessment: () => _select(0),
        ),
    ];

    return Scaffold(
      body: IndexedStack(
        index: _index.clamp(0, tabs.length - 1),
        children: tabs,
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index.clamp(0, destinations.length - 1),
        onDestinationSelected: _select,
        destinations: destinations,
      ),
    );
  }
}

/// The records list tab (was the whole home screen).
class RecordsTab extends StatefulWidget {
  const RecordsTab({super.key});

  @override
  State<RecordsTab> createState() => RecordsTabState();
}

class RecordsTabState extends State<RecordsTab> {
  final List<AssessmentSummary> _items = [];
  int _page = 1;
  bool _hasMore = false;
  bool _loading = false;
  String? _error;
  String _scope = 'own';
  String? _totalScopeLabel;

  @override
  void initState() {
    super.initState();
    refresh();
  }

  /// Reload from page 1 (public so the shell can refresh after a save).
  Future<void> refresh() async {
    _page = 1;
    await _load(reset: true);
  }

  Future<void> _load({bool reset = false}) async {
    if (_loading) return;
    setState(() {
      _loading = true;
      if (reset) _error = null;
    });
    final session = context.read<Session>();
    try {
      final result = await session.client.listAssessments(
        page: reset ? 1 : _page,
        perPage: 20,
      );
      if (!mounted) return;
      setState(() {
        if (reset) {
          _items
            ..clear()
            ..addAll(result.items);
          _page = 1;
        } else {
          _items.addAll(result.items);
        }
        _hasMore = result.hasMore;
        _scope = result.scope;
        _totalScopeLabel = result.scope == 'all' ? 'All records' : 'My records';
        _error = null;
      });
    } on ApiException catch (e) {
      if (e.isAuth && mounted) {
        await session.sessionExpired(e.message);
        return;
      }
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = 'Could not load records.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _openRecord(AssessmentSummary summary) async {
    final session = context.read<Session>();
    try {
      final detail = await session.client.getAssessment(summary.id);
      if (!mounted) return;
      await Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => ReportScreen(
            detail: detail,
            report: null,
            recordId: detail.id,
          ),
        ),
      );
    } on ApiException catch (e) {
      if (e.isAuth && mounted) {
        await session.sessionExpired(e.message);
      } else if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final user = session.user;
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(
        title: const BrandWordmark(),
        actions: [
          if (_totalScopeLabel != null)
            Center(
              child: Container(
                margin: const EdgeInsets.only(right: 4),
                padding:
                    const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
                decoration: BoxDecoration(
                  color: const Color(0x26FFFFFF), // white 15%
                  borderRadius: BorderRadius.circular(3),
                ),
                child: Text(
                  _totalScopeLabel!,
                  style: const TextStyle(color: Colors.white, fontSize: 12),
                ),
              ),
            ),
          if (user != null)
            Padding(
              padding: const EdgeInsets.only(right: 4),
              child: Center(
                child: Text(user.username,
                    style: const TextStyle(
                        color: Color(0xCCFFFFFF), fontSize: 13)),
              ),
            ),
          IconButton(
            tooltip: 'Sign out',
            onPressed: () => confirmLogout(context),
            icon: const Icon(Icons.logout),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: refresh,
        child: _buildBody(theme),
      ),
    );
  }

  Widget _buildBody(ThemeData theme) {
    if (_items.isEmpty && _loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_items.isEmpty && _error != null) {
      return ListView(
        padding: const EdgeInsets.all(24),
        children: [
          const SizedBox(height: 80),
          const NoticeBar(
            'Records could not be loaded — check the connection and try again.',
            kind: NoticeKind.error,
          ),
          const SizedBox(height: 12),
          Center(
            child: OutlinedButton(
                onPressed: refresh, child: const Text('Try again')),
          ),
        ],
      );
    }
    if (_items.isEmpty) {
      return ListView(
        padding: const EdgeInsets.all(24),
        children: [
          const SizedBox(height: 70),
          const Icon(Icons.inbox, size: 46, color: Brand.ruleStrong),
          const SizedBox(height: 14),
          const Text(
            'No records yet.',
            textAlign: TextAlign.center,
            style: TextStyle(
              fontFamily: Brand.display,
              fontSize: 22,
              fontWeight: FontWeight.w600,
              color: Brand.brand,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            _scope == 'all'
                ? 'Open the New tab to score the first assessment.'
                : 'Open the New tab to score your first assessment.',
            textAlign: TextAlign.center,
            style: theme.textTheme.bodySmall?.copyWith(color: Brand.muted),
          ),
        ],
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(12, 8, 12, 24),
      itemCount: _items.length + (_hasMore ? 1 : 0),
      itemBuilder: (context, index) {
        if (index == _items.length) {
          return Padding(
            padding: const EdgeInsets.symmetric(vertical: 12),
            child: Center(
              child: _loading
                  ? const CircularProgressIndicator()
                  : TextButton(
                      onPressed: () {
                        _page += 1;
                        _load();
                      },
                      child: const Text('Load more'),
                    ),
            ),
          );
        }
        final item = _items[index];
        return Card(
          margin: const EdgeInsets.symmetric(vertical: 5),
          child: ListTile(
            leading: CircleAvatar(
              backgroundColor: Brand.brandSoft,
              child: Text(
                '${item.autismIndex}',
                style: const TextStyle(
                  fontWeight: FontWeight.w700,
                  color: Brand.brand,
                ),
              ),
            ),
            title: Text(item.individualName,
                maxLines: 1, overflow: TextOverflow.ellipsis),
            subtitle: Text(
              [
                if (item.dateOfRating != null && item.dateOfRating!.isNotEmpty)
                  item.dateOfRating!,
                item.severityLevel,
                if (item.isMute) 'mute',
              ].join(' · '),
              style: const TextStyle(color: Brand.muted, fontSize: 13),
            ),
            trailing: const Icon(Icons.chevron_right, color: Brand.muted),
            onTap: () => _openRecord(item),
          ),
        );
      },
    );
  }
}
