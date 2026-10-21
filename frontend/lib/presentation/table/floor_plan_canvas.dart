import 'dart:math' as math;
import 'package:flutter/material.dart';
import '../../core/table/table_models.dart';

class FloorPlanCanvas extends StatefulWidget {
  final FloorPlanData floorPlan;
  final List<TableModel> tables;
  final bool isEditor;
  final double dragSensitivity;
  final TableModel? selectedTable;
  final ValueChanged<TableModel>? onTableSelected;
  final ValueChanged<FloorPlanElement>? onElementSelected;
  final void Function(List<TableModel> updatedTables)? onTablesUpdated;
  final void Function(FloorPlanData updatedPlan)? onPlanUpdated;

  const FloorPlanCanvas({
    super.key,
    required this.floorPlan,
    required this.tables,
    this.isEditor = false,
    this.dragSensitivity = 1.4,
    this.selectedTable,
    this.onTableSelected,
    this.onElementSelected,
    this.onTablesUpdated,
    this.onPlanUpdated,
  });

  @override
  State<FloorPlanCanvas> createState() => _FloorPlanCanvasState();
}

class _FloorPlanCanvasState extends State<FloorPlanCanvas> {
  late List<TableModel> _localTables;
  late FloorPlanData _localPlan;
  final TransformationController _transformController = TransformationController();
  bool _isDraggingItem = false;

  @override
  void initState() {
    super.initState();
    _localTables = List.from(widget.tables);
    _localPlan = widget.floorPlan;
  }

  @override
  void didUpdateWidget(covariant FloorPlanCanvas oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!widget.isEditor || oldWidget.tables.length != widget.tables.length) {
      _localTables = List.from(widget.tables);
    }
    if (!widget.isEditor || oldWidget.floorPlan != widget.floorPlan) {
      _localPlan = widget.floorPlan;
    }
  }

  @override
  void dispose() {
    _transformController.dispose();
    super.dispose();
  }

  double _getEffectiveDelta(double screenDelta) {
    final scale = _transformController.value.getMaxScaleOnAxis().clamp(0.2, 5.0);
    return (screenDelta / scale) * widget.dragSensitivity;
  }

  @override
  Widget build(BuildContext context) {
    final canvasWidth = _localPlan.canvasWidth;
    final canvasHeight = _localPlan.canvasHeight;

    return Container(
      color: const Color(0xFF0F172A), // Dark slate theme
      child: InteractiveViewer(
        transformationController: _transformController,
        constrained: false,
        panEnabled: !_isDraggingItem,
        boundaryMargin: const EdgeInsets.all(240),
        minScale: 0.5,
        maxScale: 2.5,
        child: SizedBox(
          width: canvasWidth,
          height: canvasHeight,
          child: Stack(
            children: [
              // Grid Dot Architectural Background
              Positioned.fill(
                child: CustomPaint(
                  painter: _GridBackgroundPainter(),
                ),
              ),

              // 1. Zones (Area Indoor, Outdoor, etc.)
              ..._localPlan.elements
                  .where((e) => e.type == 'zone')
                  .map((zone) => _buildZoneWidget(zone)),

              // 2. Base Walls (Lines)
              Positioned.fill(
                child: CustomPaint(
                  painter: _WallsPainter(
                    walls: _localPlan.elements.where((e) => e.type == 'wall').toList(),
                  ),
                ),
              ),

              // 2b. Interactive Wall Handles & Controls (Editor Mode only)
              if (widget.isEditor)
                ..._localPlan.elements
                    .where((e) => e.type == 'wall')
                    .map((wall) => _buildInteractiveWallControls(wall)),

              // 3. Text Landmarks (Kasir, Pintu, Panggung)
              ..._localPlan.elements
                  .where((e) => e.type == 'text')
                  .map((txt) => _buildTextLandmarkWidget(txt)),

              // 4. Tables with Cinema-style seat visualization
              ..._localTables.map((table) => _buildTableWidget(table)),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildZoneWidget(FloorPlanElement zone) {
    final x = zone.x ?? 0;
    final y = zone.y ?? 0;
    final w = zone.width ?? 300;
    final h = zone.height ?? 300;
    final isOutdoor = (zone.label ?? '').toLowerCase().contains('outdoor');

    return Positioned(
      left: x,
      top: y,
      width: w,
      height: h,
      child: Container(
        decoration: BoxDecoration(
          color: isOutdoor
              ? const Color(0xFF064E3B).withValues(alpha: 0.22)
              : const Color(0xFF1E293B).withValues(alpha: 0.35),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(
            color: isOutdoor
                ? const Color(0xFF10B981).withValues(alpha: 0.4)
                : const Color(0xFF38BDF8).withValues(alpha: 0.35),
            width: 2,
            strokeAlign: BorderSide.strokeAlignInside,
          ),
        ),
        padding: const EdgeInsets.all(12),
        alignment: Alignment.topLeft,
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
              decoration: BoxDecoration(
                color: isOutdoor
                    ? const Color(0xFF065F46).withValues(alpha: 0.8)
                    : const Color(0xFF0F172A).withValues(alpha: 0.8),
                borderRadius: BorderRadius.circular(20),
                border: Border.all(
                  color: isOutdoor ? const Color(0xFF34D399) : const Color(0xFF38BDF8),
                  width: 1,
                ),
              ),
              child: Text(
                zone.label ?? 'Area',
                style: TextStyle(
                  color: isOutdoor ? const Color(0xFF6EE7B7) : const Color(0xFFBAE6FD),
                  fontWeight: FontWeight.bold,
                  fontSize: 12,
                  letterSpacing: 0.5,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// 🧱 Interactive Controls for Walls/Dividers (Move center, resize start/end endpoints)
  Widget _buildInteractiveWallControls(FloorPlanElement wall) {
    final x1 = wall.x1 ?? 0;
    final y1 = wall.y1 ?? 0;
    final x2 = wall.x2 ?? 0;
    final y2 = wall.y2 ?? 0;
    final midX = (x1 + x2) / 2;
    final midY = (y1 + y2) / 2;

    const handleRadius = 14.0;
    const centerBtnSize = 32.0;

    return Stack(
      children: [
        // 1. Center Move & Edit Button (Drags entire wall, tap to edit/delete)
        Positioned(
          left: midX - (centerBtnSize / 2),
          top: midY - (centerBtnSize / 2),
          child: GestureDetector(
            onPanStart: (_) => setState(() => _isDraggingItem = true),
            onPanUpdate: (details) {
              final dx = _getEffectiveDelta(details.delta.dx);
              final dy = _getEffectiveDelta(details.delta.dy);
              final idx = _localPlan.elements.indexWhere((e) => e.id == wall.id);
              if (idx != -1) {
                setState(() {
                  _localPlan.elements[idx] = wall.copyWith(
                    x1: (wall.x1 ?? 0) + dx,
                    y1: (wall.y1 ?? 0) + dy,
                    x2: (wall.x2 ?? 0) + dx,
                    y2: (wall.y2 ?? 0) + dy,
                  );
                });
              }
            },
            onPanEnd: (_) {
              setState(() => _isDraggingItem = false);
              widget.onPlanUpdated?.call(_localPlan);
            },
            onTap: () {
              if (widget.onElementSelected != null) {
                widget.onElementSelected!(wall);
              }
            },
            child: Container(
              width: centerBtnSize,
              height: centerBtnSize,
              decoration: BoxDecoration(
                color: const Color(0xFF0F172A),
                shape: BoxShape.circle,
                border: Border.all(color: Colors.amber, width: 2),
                boxShadow: const [
                  BoxShadow(color: Colors.amberAccent, blurRadius: 8, spreadRadius: 1),
                ],
              ),
              child: const Icon(Icons.open_with, color: Colors.amber, size: 16),
            ),
          ),
        ),

        // 2. Endpoint 1 Handle (Drags start position of wall)
        Positioned(
          left: x1 - (handleRadius / 2),
          top: y1 - (handleRadius / 2),
          child: GestureDetector(
            onPanStart: (_) => setState(() => _isDraggingItem = true),
            onPanUpdate: (details) {
              final dx = _getEffectiveDelta(details.delta.dx);
              final dy = _getEffectiveDelta(details.delta.dy);
              final idx = _localPlan.elements.indexWhere((e) => e.id == wall.id);
              if (idx != -1) {
                setState(() {
                  _localPlan.elements[idx] = wall.copyWith(
                    x1: (wall.x1 ?? 0) + dx,
                    y1: (wall.y1 ?? 0) + dy,
                  );
                });
              }
            },
            onPanEnd: (_) {
              setState(() => _isDraggingItem = false);
              widget.onPlanUpdated?.call(_localPlan);
            },
            child: Container(
              width: handleRadius,
              height: handleRadius,
              decoration: BoxDecoration(
                color: Colors.amber.shade400,
                shape: BoxShape.circle,
                border: Border.all(color: Colors.black, width: 2),
                boxShadow: const [
                  BoxShadow(color: Colors.black54, blurRadius: 4),
                ],
              ),
            ),
          ),
        ),

        // 3. Endpoint 2 Handle (Drags end position of wall)
        Positioned(
          left: x2 - (handleRadius / 2),
          top: y2 - (handleRadius / 2),
          child: GestureDetector(
            onPanStart: (_) => setState(() => _isDraggingItem = true),
            onPanUpdate: (details) {
              final dx = _getEffectiveDelta(details.delta.dx);
              final dy = _getEffectiveDelta(details.delta.dy);
              final idx = _localPlan.elements.indexWhere((e) => e.id == wall.id);
              if (idx != -1) {
                setState(() {
                  _localPlan.elements[idx] = wall.copyWith(
                    x2: (wall.x2 ?? 0) + dx,
                    y2: (wall.y2 ?? 0) + dy,
                  );
                });
              }
            },
            onPanEnd: (_) {
              setState(() => _isDraggingItem = false);
              widget.onPlanUpdated?.call(_localPlan);
            },
            child: Container(
              width: handleRadius,
              height: handleRadius,
              decoration: BoxDecoration(
                color: Colors.amber.shade400,
                shape: BoxShape.circle,
                border: Border.all(color: Colors.black, width: 2),
                boxShadow: const [
                  BoxShadow(color: Colors.black54, blurRadius: 4),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildTextLandmarkWidget(FloorPlanElement txt) {
    final x = txt.x ?? 50;
    final y = txt.y ?? 50;
    final fontSize = txt.fontSize ?? 13.0;

    return Positioned(
      left: x,
      top: y,
      child: GestureDetector(
        onPanStart: widget.isEditor ? (_) => setState(() => _isDraggingItem = true) : null,
        onPanUpdate: widget.isEditor
            ? (details) {
                final dx = _getEffectiveDelta(details.delta.dx);
                final dy = _getEffectiveDelta(details.delta.dy);
                setState(() {
                  final idx = _localPlan.elements.indexWhere((e) => e.id == txt.id);
                  if (idx != -1) {
                    final updatedElem = txt.copyWith(
                      x: math.max(10, x + dx),
                      y: math.max(10, y + dy),
                    );
                    _localPlan.elements[idx] = updatedElem;
                  }
                });
              }
            : null,
        onPanEnd: widget.isEditor
            ? (_) {
                setState(() => _isDraggingItem = false);
                widget.onPlanUpdated?.call(_localPlan);
              }
            : null,
        onTap: widget.isEditor
            ? () {
                if (widget.onElementSelected != null) {
                  widget.onElementSelected!(txt);
                }
              }
            : null,
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
          decoration: BoxDecoration(
            color: const Color(0xFF1E293B).withValues(alpha: 0.85),
            borderRadius: BorderRadius.circular(8),
            border: Border.all(
              color: widget.isEditor ? Colors.amber : const Color(0xFF475569),
              width: 1,
            ),
            boxShadow: const [
              BoxShadow(
                color: Colors.black38,
                blurRadius: 6,
                offset: Offset(0, 2),
              ),
            ],
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                txt.label ?? '',
                style: TextStyle(
                  color: const Color(0xFFE2E8F0),
                  fontWeight: FontWeight.w600,
                  fontSize: fontSize,
                ),
              ),
              if (widget.isEditor) ...[
                const SizedBox(width: 4),
                const Icon(Icons.edit, size: 12, color: Colors.amber),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildTableWidget(TableModel table) {
    final isSelected = widget.selectedTable?.id == table.id;
    final isEditor = widget.isEditor;

    Color statusColor;
    Color statusBgColor;
    String statusText;

    if (!table.isActive) {
      statusColor = const Color(0xFF64748B);
      statusBgColor = const Color(0xFF334155);
      statusText = 'Nonaktif';
    } else if (table.isOccupied) {
      statusColor = const Color(0xFFEF4444);
      statusBgColor = const Color(0xFF7F1D1D);
      statusText = 'Terisi';
    } else if (table.isReserved) {
      statusColor = const Color(0xFFF59E0B);
      statusBgColor = const Color(0xFF78350F);
      statusText = 'Dipesan';
    } else {
      statusColor = const Color(0xFF10B981);
      statusBgColor = const Color(0xFF064E3B);
      statusText = 'Tersedia';
    }

    final isCircle = table.shape == 'circle';
    final width = table.tableWidth;
    final height = isCircle ? width : table.tableHeight;
    final posX = table.posX;
    final posY = table.posY;

    return Positioned(
      left: posX,
      top: posY,
      width: width,
      height: height,
      child: GestureDetector(
        onPanStart: isEditor ? (_) => setState(() => _isDraggingItem = true) : null,
        onPanUpdate: isEditor
            ? (details) {
                final dx = _getEffectiveDelta(details.delta.dx);
                final dy = _getEffectiveDelta(details.delta.dy);
                setState(() {
                  final idx = _localTables.indexWhere((t) => t.id == table.id);
                  if (idx != -1) {
                    final newX = math.max(10.0, math.min(_localPlan.canvasWidth - width - 10, posX + dx));
                    final newY = math.max(10.0, math.min(_localPlan.canvasHeight - height - 10, posY + dy));
                    _localTables[idx] = table.copyWith(x: newX, y: newY);
                  }
                });
              }
            : null,
        onPanEnd: isEditor
            ? (_) {
                setState(() => _isDraggingItem = false);
                widget.onTablesUpdated?.call(_localTables);
              }
            : null,
        onTap: () {
          if (widget.onTableSelected != null) {
            widget.onTableSelected!(table);
          }
        },
        child: Container(
          decoration: BoxDecoration(
            color: isSelected
                ? const Color(0xFF0284C7).withValues(alpha: 0.9)
                : statusBgColor.withValues(alpha: 0.8),
            shape: isCircle ? BoxShape.circle : BoxShape.rectangle,
            borderRadius: isCircle ? null : BorderRadius.circular(12),
            border: Border.all(
              color: isSelected
                  ? const Color(0xFF38BDF8)
                  : isEditor
                      ? Colors.amber.shade400
                      : statusColor,
              width: isSelected ? 3 : 2,
            ),
            boxShadow: [
              BoxShadow(
                color: (isSelected ? const Color(0xFF38BDF8) : statusColor)
                    .withValues(alpha: isSelected ? 0.6 : 0.25),
                blurRadius: isSelected ? 12 : 6,
                spreadRadius: isSelected ? 2 : 0,
              ),
            ],
          ),
          child: Stack(
            children: [
              // Chair Notches (decorative realistic restaurant layout look)
              ..._buildChairs(table.capacity, width, height, isCircle, statusColor),

              // Center table label & status
              Center(
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 4),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text(
                        table.name,
                        textAlign: TextAlign.center,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.bold,
                          fontSize: isCircle ? 12 : 13,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(Icons.person, size: 11, color: Colors.grey.shade300),
                          const SizedBox(width: 2),
                          Text(
                            '${table.capacity}',
                            style: TextStyle(
                              color: Colors.grey.shade300,
                              fontSize: 11,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 2),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                        decoration: BoxDecoration(
                          color: statusColor.withValues(alpha: 0.25),
                          borderRadius: BorderRadius.circular(4),
                        ),
                        child: Text(
                          isEditor ? 'Drag' : statusText,
                          style: TextStyle(
                            color: isEditor ? Colors.amber.shade200 : statusColor,
                            fontSize: 9,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),

              if (isSelected)
                Positioned(
                  top: 4,
                  right: 4,
                  child: Container(
                    padding: const EdgeInsets.all(2),
                    decoration: const BoxDecoration(
                      color: Color(0xFF38BDF8),
                      shape: BoxShape.circle,
                    ),
                    child: const Icon(Icons.check, size: 12, color: Colors.black),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }

  List<Widget> _buildChairs(int capacity, double w, double h, bool isCircle, Color color) {
    final chairs = <Widget>[];
    const chairSize = 6.0;

    // Subtle chair dots along table boundary
    if (isCircle) {
      for (int i = 0; i < capacity; i++) {
        final angle = (2 * math.pi / capacity) * i;
        final r = (w / 2) - 4;
        final cx = (w / 2) + r * math.cos(angle) - (chairSize / 2);
        final cy = (h / 2) + r * math.sin(angle) - (chairSize / 2);
        chairs.add(
          Positioned(
            left: cx,
            top: cy,
            child: Container(
              width: chairSize,
              height: chairSize,
              decoration: BoxDecoration(
                color: color.withValues(alpha: 0.4),
                shape: BoxShape.circle,
              ),
            ),
          ),
        );
      }
    } else {
      // Top and bottom chairs
      final sideCount = math.max(1, capacity ~/ 2);
      for (int i = 0; i < sideCount; i++) {
        final cx = ((w - 16) / (sideCount + 1)) * (i + 1) + 8 - (chairSize / 2);
        // Top chair
        chairs.add(
          Positioned(
            left: cx,
            top: 2,
            child: Container(
              width: chairSize,
              height: chairSize,
              decoration: BoxDecoration(
                color: color.withValues(alpha: 0.4),
                shape: BoxShape.circle,
              ),
            ),
          ),
        );
        // Bottom chair
        chairs.add(
          Positioned(
            left: cx,
            bottom: 2,
            child: Container(
              width: chairSize,
              height: chairSize,
              decoration: BoxDecoration(
                color: color.withValues(alpha: 0.4),
                shape: BoxShape.circle,
              ),
            ),
          ),
        );
      }
    }

    return chairs;
  }
}

class _GridBackgroundPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final dotPaint = Paint()
      ..color = const Color(0xFF334155).withValues(alpha: 0.35)
      ..strokeWidth = 1.5;

    const step = 24.0;
    for (double x = 0; x < size.width; x += step) {
      for (double y = 0; y < size.height; y += step) {
        canvas.drawCircle(Offset(x, y), 1.0, dotPaint);
      }
    }
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _WallsPainter extends CustomPainter {
  final List<FloorPlanElement> walls;

  _WallsPainter({required this.walls});

  @override
  void paint(Canvas canvas, Size size) {
    for (final wall in walls) {
      final x1 = wall.x1 ?? 0;
      final y1 = wall.y1 ?? 0;
      final x2 = wall.x2 ?? 0;
      final y2 = wall.y2 ?? 0;
      final thickness = wall.thickness ?? 6.0;

      final paint = Paint()
        ..color = const Color(0xFF64748B)
        ..strokeWidth = thickness
        ..strokeCap = StrokeCap.round;

      // Glow shadow
      final shadowPaint = Paint()
        ..color = Colors.black45
        ..strokeWidth = thickness + 4
        ..strokeCap = StrokeCap.round
        ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 3);

      canvas.drawLine(Offset(x1, y1), Offset(x2, y2), shadowPaint);
      canvas.drawLine(Offset(x1, y1), Offset(x2, y2), paint);
    }
  }

  @override
  bool shouldRepaint(covariant _WallsPainter oldDelegate) =>
      oldDelegate.walls != walls;
}
