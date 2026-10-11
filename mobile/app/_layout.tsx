import { Slot } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { Page } from '../src/components/Page';
import { AuthProvider } from '../src/lib/auth';

export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <AuthProvider>
        <StatusBar style="dark" />
        <Page>
          <Slot />
        </Page>
      </AuthProvider>
    </SafeAreaProvider>
  );
}
