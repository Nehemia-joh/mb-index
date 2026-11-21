import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../api/models.dart';
import '../state/session.dart';
import 'form_screen.dart';
import 'report_screen.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  final List<AssessmentSummary> _items = [];
  int _page = 1;
  bool _hasMore = false;
  bool _loading = false;
  String? _error;
  String _scope = 'own';

  @override
  void initState() {
    super.initState();
    _load(reset: true);
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

  Future<void> _openForm() async {
    final refreshed = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (_) => const FormScreen()),
    );
    if (refreshed == true) _load(reset: true);
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

  Future<void> _confirmLogout() async {
    final session = context.read<Session>();
    final yes = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Sign out?'),
        content: Text('Signed in as ${session.user?.fullName ?? ''}.'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel')),
          FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Sign out')),
        ],
      ),
    );
    if (yes == true) await session.logout();
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final user = session.user;
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Records'),
        actions: [
          if (user != null)
            Padding(
              padding: const EdgeInsets.only(right: 4),
              child: Center(
                child: Text(user.username,
                    style: theme.textTheme.labelMedium),
              ),
            ),
          IconButton(
            tooltip: 'Sign out',
            onPressed: _confirmLogout,
            icon: const Icon(Icons.logout),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _openForm,
        icon: const Icon(Icons.add),
        label: const Text('New assessment'),
      ),
      body: RefreshIndicator(
        onRefresh: () => _load(reset: true),
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
          Icon(Icons.cloud_off, size: 48, color: theme.colorScheme.outline),
          const SizedBox(height: 12),
          Text(_error!, textAlign: TextAlign.center),
          const SizedBox(height: 12),
          Center(
            child: OutlinedButton(
                onPressed: () => _load(reset: true),
                child: const Text('Try again')),
          ),
        ],
      );
    }
    if (_items.isEmpty) {
      return ListView(
        padding: const EdgeInsets.all(24),
        children: [
          const SizedBox(height: 80),
          Icon(Icons.inbox, size: 48, color: theme.colorScheme.outline),
          const SizedBox(height: 12),
          const Text('No records yet.',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 16)),
          const SizedBox(height: 6),
          Text(
            _scope == 'all'
                ? 'Tap “New assessment” to score the first one.'
                : 'Tap “New assessment” to score your first one.',
            textAlign: TextAlign.center,
            style: theme.textTheme.bodySmall,
          ),
        ],
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(12, 8, 12, 96),
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
              backgroundColor: theme.colorScheme.primaryContainer,
              child: Text('${item.autismIndex}',
                  style: TextStyle(
                      fontWeight: FontWeight.bold,
                      color: theme.colorScheme.onPrimaryContainer)),
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
            ),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => _openRecord(item),
          ),
        );
      },
    );
  }
}
