import { Link } from 'expo-router';
import { Text, View } from 'react-native';
import { formatDate, InfoRow } from '../../src/components/ui';
import { useAuth } from '../../src/lib/auth';
import { styles } from '../../src/styles';

export default function Dashboard() {
  const { user } = useAuth();
  if (!user) return null;

  return (
    <View>
      <Text style={styles.heading}>Dashboard</Text>
      <Text style={styles.text}>Welcome, {user.name}. You are logged in.</Text>

      <InfoRow label="Email" value={user.email} />
      <InfoRow label="Role" value={user.role} />
      <InfoRow label="Last login" value={formatDate(user.last_login_at)} />

      {user.role === 'admin' ? (
        <View style={{ marginTop: 12 }}>
          <Text style={styles.text}>You are an administrator and can see the list of registered users.</Text>
          <Link href="/admin" style={styles.link}>Open the admin page</Link>
        </View>
      ) : null}
    </View>
  );
}
