import { Redirect, Slot } from 'expo-router';
import { Text } from 'react-native';
import { useAuth } from '../../src/lib/auth';
import { styles } from '../../src/styles';

// Pages for logged-in users. This only controls what is shown:
// the server checks the session (and the role) again on every API request.
export default function AppGroupLayout() {
  const { status } = useAuth();
  if (status === 'loading') return <Text style={styles.text}>Loading...</Text>;
  if (status === 'signedOut') return <Redirect href="/sign-in" />;
  return <Slot />;
}
