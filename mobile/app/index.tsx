import { Redirect } from 'expo-router';
import { Text } from 'react-native';
import { useAuth } from '../src/lib/auth';
import { styles } from '../src/styles';

export default function Index() {
  const { status } = useAuth();
  if (status === 'loading') return <Text style={styles.text}>Loading...</Text>;
  return <Redirect href={status === 'signedIn' ? '/dashboard' : '/sign-in'} />;
}
