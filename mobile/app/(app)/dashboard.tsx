import { Link } from 'expo-router';
import { Text, View } from 'react-native';
import { Avatar, Box, DetailRow, formatDate, RoleBadge } from '../../src/components/ui';
import { useAuth } from '../../src/lib/auth';
import { styles } from '../../src/styles';

export default function Dashboard() {
  const { user } = useAuth();
  if (!user) return null;

  return (
    <View style={{ width: '100%', maxWidth: 560, alignSelf: 'center' }}>
      <Text style={styles.heading}>Dashboard</Text>

      <Box>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
          <Avatar name={user.name} />
          <View style={{ flex: 1 }}>
            <Text style={styles.name}>Welcome, {user.name}</Text>
            <Text style={styles.small}>You are logged in with your password and a verification code.</Text>
          </View>
        </View>

        <View style={styles.divider} />

        <DetailRow label="Email">{user.email}</DetailRow>
        <DetailRow label="Role">
          <View style={{ flex: 1 }}>
            <RoleBadge role={user.role} />
          </View>
        </DetailRow>
        <DetailRow label="Last login">{formatDate(user.last_login_at)}</DetailRow>
        <DetailRow label="Registered on">{formatDate(user.created_at)}</DetailRow>
      </Box>

      {user.role === 'admin' ? (
        <Box>
          <Text style={styles.text}>You are an administrator.</Text>
          <Link href="/admin" style={[styles.link, { marginTop: 4 }]}>Users: view, delete or restore accounts</Link>
          <Link href="/backup" style={[styles.link, { marginTop: 8 }]}>Backup and restore points</Link>
          <Link href="/reports" style={[styles.link, { marginTop: 8 }]}>Reports</Link>
          <Link href="/password-demo" style={[styles.link, { marginTop: 8 }]}>Password demo (salt and pepper)</Link>
        </Box>
      ) : null}
    </View>
  );
}
