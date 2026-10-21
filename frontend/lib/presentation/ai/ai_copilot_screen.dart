import 'package:flutter/material.dart';
import '../../core/ai/ai_copilot_models.dart';
import '../../core/ai/ai_copilot_service.dart';

class AiCopilotScreen extends StatefulWidget {
  final AiCopilotService copilotService;

  const AiCopilotScreen({super.key, required this.copilotService});

  @override
  State<AiCopilotScreen> createState() => _AiCopilotScreenState();
}

class _AiCopilotScreenState extends State<AiCopilotScreen> {
  final _inputController = TextEditingController();
  final _scrollController = ScrollController();

  final List<String> _quickSuggestions = [
    'Omzet hari ini?',
    'Produk paling laku minggu ini?',
    'Cek stok kopi?',
    'Prediksi penjualan minggu depan?',
    'Cek anomali hari ini?',
  ];

  @override
  void initState() {
    super.initState();
    widget.copilotService.addListener(_onServiceChanged);
  }

  @override
  void dispose() {
    widget.copilotService.removeListener(_onServiceChanged);
    _inputController.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  void _onServiceChanged() {
    if (mounted) {
      setState(() {});
      _scrollToBottom();
    }
  }

  void _scrollToBottom() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scrollController.hasClients) {
        _scrollController.animateTo(
          _scrollController.position.maxScrollExtent,
          duration: const Duration(milliseconds: 300),
          curve: Curves.easeOut,
        );
      }
    });
  }

  void _sendMessage([String? presetText]) {
    final text = presetText ?? _inputController.text;
    if (text.trim().isEmpty) return;
    _inputController.clear();
    widget.copilotService.askQuestion(text);
  }

  @override
  Widget build(BuildContext context) {
    final service = widget.copilotService;
    final messages = service.messages;

    return Scaffold(
      appBar: AppBar(
        title: const Row(
          children: [
            Icon(Icons.auto_awesome, color: Colors.amber),
            SizedBox(width: 8),
            Text('AI Business Copilot'),
          ],
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.delete_sweep_outlined),
            onPressed: () => service.clearHistory(),
            tooltip: 'Bersihkan Percakapan',
          ),
        ],
      ),
      body: Column(
        children: [
          // Quick suggestion chips
          Container(
            height: 48,
            padding: const EdgeInsets.symmetric(horizontal: 12),
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              itemCount: _quickSuggestions.length,
              separatorBuilder: (_, _) => const SizedBox(width: 8),
              itemBuilder: (context, index) {
                final suggestion = _quickSuggestions[index];
                return ActionChip(
                  label: Text(suggestion, style: const TextStyle(fontSize: 12)),
                  avatar: const Icon(Icons.lightbulb_outline, size: 14),
                  onPressed: service.isLoading ? null : () => _sendMessage(suggestion),
                );
              },
            ),
          ),
          const Divider(height: 1),
          // Chat message list
          Expanded(
            child: ListView.builder(
              controller: _scrollController,
              padding: const EdgeInsets.all(16),
              itemCount: messages.length,
              itemBuilder: (context, index) {
                final msg = messages[index];
                return _buildMessageBubble(msg);
              },
            ),
          ),
          if (service.isLoading)
            Container(
              padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 16),
              child: const Row(
                children: [
                  SizedBox(
                    width: 16,
                    height: 16,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  ),
                  SizedBox(width: 10),
                  Text(
                    'AI sedang menganalisis data bisnis...',
                    style: TextStyle(fontSize: 12, fontStyle: FontStyle.italic, color: Colors.grey),
                  ),
                ],
              ),
            ),
          // Input box
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: Theme.of(context).cardColor,
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withValues(alpha: 0.05),
                  blurRadius: 4,
                  offset: const Offset(0, -2),
                ),
              ],
            ),
            child: SafeArea(
              child: Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _inputController,
                      decoration: InputDecoration(
                        hintText: 'Tanyakan sesuatu (omzet, stok, menu terlaris)...',
                        border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(24),
                          borderSide: BorderSide(color: Colors.grey.shade300),
                        ),
                        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                        isDense: true,
                      ),
                      onSubmitted: (_) => _sendMessage(),
                    ),
                  ),
                  const SizedBox(width: 8),
                  IconButton.filled(
                    onPressed: service.isLoading ? null : () => _sendMessage(),
                    icon: const Icon(Icons.send),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildMessageBubble(CopilotChatMessage msg) {
    if (msg.isUser) {
      return Align(
        alignment: Alignment.centerRight,
        child: Container(
          margin: const EdgeInsets.only(bottom: 12, left: 48),
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          decoration: BoxDecoration(
            color: Colors.teal.shade600,
            borderRadius: const BorderRadius.only(
              topLeft: Radius.circular(16),
              topRight: Radius.circular(16),
              bottomLeft: Radius.circular(16),
            ),
          ),
          child: Text(
            msg.text,
            style: const TextStyle(color: Colors.white, fontSize: 14),
          ),
        ),
      );
    }

    return Align(
      alignment: Alignment.centerLeft,
      child: Container(
        margin: const EdgeInsets.only(bottom: 16, right: 32),
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Colors.grey.shade50,
          borderRadius: const BorderRadius.only(
            topLeft: Radius.circular(16),
            topRight: Radius.circular(16),
            bottomRight: Radius.circular(16),
          ),
          border: Border.all(color: Colors.grey.shade200),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                CircleAvatar(
                  radius: 12,
                  backgroundColor: Colors.amber.shade100,
                  child: const Icon(Icons.auto_awesome, size: 14, color: Colors.amber),
                ),
                const SizedBox(width: 8),
                const Text(
                  'AI Business Copilot',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12),
                ),
              ],
            ),
            const SizedBox(height: 8),
            SelectableText(
              msg.text,
              style: const TextStyle(fontSize: 14, height: 1.4),
            ),
            if (msg.responseData != null) ...[
              const Divider(height: 16),
              Row(
                children: [
                  Icon(Icons.verified_outlined, size: 12, color: Colors.grey.shade600),
                  const SizedBox(width: 4),
                  Text(
                    'Sumber: ${msg.responseData!.source} • Periode: ${msg.responseData!.period}',
                    style: TextStyle(fontSize: 11, color: Colors.grey.shade600),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}
