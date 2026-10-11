import { Platform } from 'react-native';
import * as SecureStore from 'expo-secure-store';

const TOKEN_KEY = 'session_token';

// Phone: the token is kept in Expo SecureStore (Keychain / Keystore).
// Web: nothing is stored, because localStorage can be read by any script on the page.
// The browser version uses an HttpOnly cookie instead.

export async function saveToken(token: string): Promise<void> {
  if (Platform.OS === 'web') return;
  await SecureStore.setItemAsync(TOKEN_KEY, token);
}

export async function loadToken(): Promise<string | null> {
  if (Platform.OS === 'web') return null;
  try {
    return await SecureStore.getItemAsync(TOKEN_KEY);
  } catch {
    return null;
  }
}

export async function clearToken(): Promise<void> {
  if (Platform.OS === 'web') return;
  try {
    await SecureStore.deleteItemAsync(TOKEN_KEY);
  } catch {
    // nothing to clear
  }
}
