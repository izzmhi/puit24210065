import { Text, View } from 'react-native';
import { Button, formatDate, InfoRow } from '../../src/components/ui';
import { useAuth } from '../../src/lib/auth';
import { styles } from '../../src/styles';

export default function Profile() {
  const { user, signOut } = useAuth();
  if (!user) return null;

  return (
    <View style={styles.form}>
      <Text style={styles.heading}>Profile</Text>

      <InfoRow label="Name" value={user.name} />
      <InfoRow label="Email" value={user.email} />
      <InfoRow label="Role" value={user.role} />
      <InfoRow label="Registered on" value={formatDate(user.created_at)} />
      <InfoRow label="Last login" value={formatDate(user.last_login_at)} />

      <Button title="Logout" onPress={() => void signOut()} secondary />
    </View>
  );
}
