import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../services/user_service.dart';
import '../../models/vehicle_model.dart';
import '../../utils/app_theme.dart';
import '../../utils/constants.dart';

class VehiclesScreen extends StatefulWidget {
  const VehiclesScreen({super.key});
  @override
  State<VehiclesScreen> createState() => _VehiclesScreenState();
}

class _VehiclesScreenState extends State<VehiclesScreen> {
  List<VehicleModel> _vehicles = [];
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      _vehicles = await context.read<UserService>().getVehicles();
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.danger));
    } finally {
      setState(() => _loading = false);
    }
  }

  Future<void> _deleteVehicle(int id) async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Delete Vehicle'),
        content: const Text('Are you sure you want to remove this vehicle?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          TextButton(onPressed: () => Navigator.pop(ctx, true), style: TextButton.styleFrom(foregroundColor: AppTheme.danger), child: const Text('Delete')),
        ],
      ),
    );
    if (confirm != true || !mounted) return;
    try {
      await context.read<UserService>().deleteVehicle(id);
      _load();
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.danger));
    }
  }

  void _showAddVehicleDialog([VehicleModel? vehicle]) {
    showDialog(context: context, builder: (ctx) => _VehicleDialog(vehicle: vehicle, onSave: (data) async {
      await context.read<UserService>().addVehicle(data);
      _load();
    }));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('My Vehicles'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        actions: [
          TextButton.icon(
            onPressed: () => _showAddVehicleDialog(),
            icon: const Icon(Icons.add, color: AppTheme.accent),
            label: const Text('Add Vehicle', style: TextStyle(color: AppTheme.accent)),
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _vehicles.isEmpty
              ? Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                  const Icon(Icons.directions_car_outlined, size: 64, color: AppTheme.textSecondary),
                  const SizedBox(height: 12),
                  const Text('No vehicles yet', style: TextStyle(color: AppTheme.textSecondary)),
                  const SizedBox(height: 16),
                  ElevatedButton.icon(onPressed: () => _showAddVehicleDialog(), icon: const Icon(Icons.add), label: const Text('Add Vehicle')),
                ]))
              : GridView.builder(
                  padding: const EdgeInsets.all(16),
                  gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(maxCrossAxisExtent: 360, childAspectRatio: 1.4, crossAxisSpacing: 16, mainAxisSpacing: 16),
                  itemCount: _vehicles.length,
                  itemBuilder: (ctx, i) {
                    final v = _vehicles[i];
                    return Card(
                      child: Padding(
                        padding: const EdgeInsets.all(16),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(children: [
                              Container(
                                width: 44, height: 44,
                                decoration: BoxDecoration(color: AppTheme.accent.withOpacity(0.15), borderRadius: BorderRadius.circular(10)),
                                child: const Icon(Icons.directions_car, color: AppTheme.accent),
                              ),
                              const SizedBox(width: 10),
                              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                Text(v.displayName.isEmpty ? v.type.toUpperCase() : v.displayName, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                                Text(v.type.toUpperCase(), style: const TextStyle(color: AppTheme.textSecondary, fontSize: 11, letterSpacing: 0.5)),
                              ])),
                              PopupMenuButton<String>(
                                onSelected: (val) { if (val == 'delete') _deleteVehicle(v.id); },
                                itemBuilder: (_) => [
                                  const PopupMenuItem(value: 'delete', child: ListTile(leading: Icon(Icons.delete, color: AppTheme.danger), title: Text('Delete'))),
                                ],
                              ),
                            ]),
                            const SizedBox(height: 12),
                            if (v.plateNumber != null) Text('Plate: ${v.plateNumber}', style: const TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
                            const Spacer(),
                            Row(children: [
                              _ChipBadge(label: '${v.seats} seats', icon: Icons.people),
                              const SizedBox(width: 4),
                              if (v.hasAc) _ChipBadge(label: 'AC', icon: Icons.ac_unit),
                              if (v.hasWifi) _ChipBadge(label: 'WiFi', icon: Icons.wifi),
                            ]),
                          ],
                        ),
                      ),
                    );
                  },
                ),
    );
  }
}

class _ChipBadge extends StatelessWidget {
  final String label;
  final IconData icon;
  const _ChipBadge({required this.label, required this.icon});
  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 3),
    decoration: BoxDecoration(color: AppTheme.info.withOpacity(0.1), borderRadius: BorderRadius.circular(8)),
    child: Row(mainAxisSize: MainAxisSize.min, children: [
      Icon(icon, size: 11, color: AppTheme.info),
      const SizedBox(width: 3),
      Text(label, style: const TextStyle(fontSize: 10, color: AppTheme.info, fontWeight: FontWeight.w600)),
    ]),
  );
}

class _VehicleDialog extends StatefulWidget {
  final VehicleModel? vehicle;
  final Future<void> Function(Map<String, dynamic>) onSave;
  const _VehicleDialog({this.vehicle, required this.onSave});
  @override
  State<_VehicleDialog> createState() => _VehicleDialogState();
}

class _VehicleDialogState extends State<_VehicleDialog> {
  final _makeCtrl = TextEditingController();
  final _modelCtrl = TextEditingController();
  final _plateCtrl = TextEditingController();
  String _type = 'car';
  int _seats = 4;
  bool _hasAc = false;
  bool _hasWifi = false;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    if (widget.vehicle != null) {
      final v = widget.vehicle!;
      _type = v.type;
      _makeCtrl.text = v.make ?? '';
      _modelCtrl.text = v.model ?? '';
      _plateCtrl.text = v.plateNumber ?? '';
      _seats = v.seats;
      _hasAc = v.hasAc;
      _hasWifi = v.hasWifi;
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(widget.vehicle == null ? 'Add Vehicle' : 'Edit Vehicle'),
      content: SizedBox(
        width: 400,
        child: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
          DropdownButtonFormField<String>(
            value: _type,
            decoration: const InputDecoration(labelText: 'Type'),
            items: AppConstants.vehicleTypes.map((t) => DropdownMenuItem(value: t, child: Text(t.toUpperCase()))).toList(),
            onChanged: (v) => setState(() => _type = v!),
          ),
          const SizedBox(height: 12),
          TextField(controller: _makeCtrl, decoration: const InputDecoration(labelText: 'Make (e.g. Toyota)')),
          const SizedBox(height: 12),
          TextField(controller: _modelCtrl, decoration: const InputDecoration(labelText: 'Model (e.g. Corolla)')),
          const SizedBox(height: 12),
          TextField(controller: _plateCtrl, decoration: const InputDecoration(labelText: 'Plate Number')),
          const SizedBox(height: 12),
          Row(children: [
            const Text('Seats:'),
            const SizedBox(width: 12),
            IconButton(icon: const Icon(Icons.remove), onPressed: _seats > 1 ? () => setState(() => _seats--) : null),
            Text('$_seats', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
            IconButton(icon: const Icon(Icons.add), onPressed: _seats < 20 ? () => setState(() => _seats++) : null),
          ]),
          CheckboxListTile(value: _hasAc, onChanged: (v) => setState(() => _hasAc = v!), title: const Text('Air Conditioning'), dense: true),
          CheckboxListTile(value: _hasWifi, onChanged: (v) => setState(() => _hasWifi = v!), title: const Text('WiFi'), dense: true),
        ])),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')),
        ElevatedButton(
          onPressed: _loading ? null : () async {
            setState(() => _loading = true);
            try {
              await widget.onSave({
                if (widget.vehicle != null) 'id': widget.vehicle!.id,
                'type': _type, 'make': _makeCtrl.text, 'model': _modelCtrl.text,
                'plate_number': _plateCtrl.text, 'seats': _seats, 'has_ac': _hasAc, 'has_wifi': _hasWifi,
              });
              if (context.mounted) Navigator.pop(context);
            } catch (e) {
              if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.danger));
            } finally {
              if (mounted) setState(() => _loading = false);
            }
          },
          child: _loading ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : const Text('Save'),
        ),
      ],
    );
  }
}
