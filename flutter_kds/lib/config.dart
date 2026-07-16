/// Runtime configuration for the KDS client. In production these are injected
/// after the device logs in against `POST /api/v1/auth/login`; the defaults
/// point at a local development server.
class KdsConfig {
  KdsConfig({
    required this.baseUrl,
    required this.token,
    this.stationId,
    this.pollInterval = const Duration(seconds: 5),
  });

  /// API root, e.g. `http://127.0.0.1:8000/api/v1`.
  final String baseUrl;

  /// Bearer access token for the authenticated kitchen device.
  String token;

  /// Optional station filter; null shows the whole branch board.
  String? stationId;

  /// How often the board reconciles with the server.
  final Duration pollInterval;

  static KdsConfig dev() => KdsConfig(
        baseUrl: const String.fromEnvironment(
          'KDS_BASE_URL',
          defaultValue: 'http://127.0.0.1:8000/api/v1',
        ),
        token: const String.fromEnvironment('KDS_TOKEN', defaultValue: ''),
      );
}
