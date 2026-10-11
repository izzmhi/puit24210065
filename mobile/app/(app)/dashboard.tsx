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

      <Box top>
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
          <Text style={styles.text}>You are an administrator and can see the list of registered users.</Text>
          <Link href="/admin" style={[styles.link, { marginTop: 0 }]}>Open the admin page</Link>
        </Box>
      ) : null}
    </View>
  );
}
