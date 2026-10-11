import { Redirect, Slot } from 'expo-router';
import { Text } from 'react-native';
import { useAuth } from '../../src/lib/auth';
import { styles } from '../../src/styles';

// Login / register / verify pages. Someone who is already logged in goes to the dashboard.
export default function AuthGroupLayout() {
  const { status } = useAuth();
  if (status === 'loading') return <Text style={styles.text}>Loading...</Text>;
  if (status === 'signedIn') return <Redirect href="/dashboard" />;
  return <Slot />;
}
