import { Text, View } from 'react-native';
import { Avatar, Box, Button, DetailRow, formatDate, RoleBadge } from '../../src/components/ui';
import { useAuth } from '../../src/lib/auth';
import { styles } from '../../src/styles';

export default function Profile() {
  const { user, signOut } = useAuth();
  if (!user) return null;

  return (
    <View style={{ width: '100%', maxWidth: 560, alignSelf: 'center' }}>
      <Text style={styles.heading}>Profile</Text>

      <Box top>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12, marginBottom: 14 }}>
          <Avatar name={user.name} />
          <Text style={styles.name}>{user.name}</Text>
        </View>

        <DetailRow label="Name">{user.name}</DetailRow>
        <DetailRow label="Email">{user.email}</DetailRow>
        <DetailRow label="Role">
          <View style={{ flex: 1 }}>
            <RoleBadge role={user.role} />
          </View>
        </DetailRow>
        <DetailRow label="Registered on">{formatDate(user.created_at)}</DetailRow>
        <DetailRow label="Last login">{formatDate(user.last_login_at)}</DetailRow>

        <Button title="Logout" onPress={() => void signOut()} secondary />
      </Box>
    </View>
  );
}
