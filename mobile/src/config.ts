import { Platform } from 'react-native';

// Address of the PHP API. Set EXPO_PUBLIC_API_URL in mobile/.env to change it.
// Only put a URL in that variable: EXPO_PUBLIC_* values are visible to everyone.
function getApiUrl(): string {
  const fromEnv = process.env.EXPO_PUBLIC_API_URL?.trim();
  if (fromEnv) return fromEnv.replace(/\/+$/, '');

  if (Platform.OS === 'web') {
    // dev: php -S localhost:8000   |   production build: same domain, /api
    return __DEV__ ? 'http://localhost:8000/api' : '/api';
  }
  // Android emulator reaches the computer through 10.0.2.2.
  // A real phone needs the computer's IP address in EXPO_PUBLIC_API_URL.
  return Platform.OS === 'android' ? 'http://10.0.2.2:8000/api' : 'http://localhost:8000/api';
}

export const API_BASE_URL = getApiUrl();
