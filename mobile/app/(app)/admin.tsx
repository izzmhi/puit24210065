import { useCallback, useEffect, useState } from 'react';
import { ScrollView, Text, View } from 'react-native';
import { Box, Button, formatDate, Message } from '../../src/components/ui';
import { AdminUsersResponse, api, ApiError } from '../../src/lib/api';
import { describeError } from '../../src/lib/errors';
import { styles } from '../../src/styles';

// Column widths so the table lines up (the table scrolls sideways on a phone).
const widths = { id: 50, name: 170, email: 240, role: 80, last: 190 };

export default function Admin() {
  const [data, setData] = useState<AdminUsersResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      setData(await api.adminUsers());
    } catch (e) {
      setData(null);
      // The request is always sent. The server decides: a normal user gets 403.
      if (e instanceof ApiError && e.status === 403) {
        setError('Access denied (403). You do not have permission to view this page.');
      } else {
        setError(describeError(e));
      }
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <View>
      <Text style={styles.heading}>Admin: registered users</Text>

      {loading ? <Text style={styles.text}>Loading users...</Text> : null}
      {error ? <Message kind="error">{error}</Message> : null}

      {data ? (
        <Box top>
          <Text style={styles.text}>
            Total users: {data.total} ({data.summary.admins} admin, {data.summary.users} user). Active sessions:{' '}
            {data.summary.active_sessions}.
          </Text>

          <ScrollView horizontal>
            <View>
              <View style={[styles.tableRow, styles.tableHead]}>
                <Text style={[styles.cell, styles.bold, { width: widths.id }]}>ID</Text>
                <Text style={[styles.cell, styles.bold, { width: widths.name }]}>Name</Text>
                <Text style={[styles.cell, styles.bold, { width: widths.email }]}>Email</Text>
                <Text style={[styles.cell, styles.bold, { width: widths.role }]}>Role</Text>
                <Text style={[styles.cell, styles.bold, { width: widths.last }]}>Last login</Text>
              </View>
              {data.users.map((u) => (
                <View key={u.id} style={styles.tableRow}>
                  <Text style={[styles.cell, { width: widths.id }]}>{u.id}</Text>
                  <Text style={[styles.cell, { width: widths.name }]}>{u.name}</Text>
                  <Text style={[styles.cell, { width: widths.email }]}>{u.email}</Text>
                  <Text style={[styles.cell, { width: widths.role }]}>{u.role}</Text>
                  <Text style={[styles.cell, { width: widths.last }]}>{formatDate(u.last_login_at)}</Text>
                </View>
              ))}
            </View>
          </ScrollView>
        </Box>
      ) : null}

      <View style={{ maxWidth: 200 }}>
        <Button title="Refresh" onPress={load} loading={loading} secondary />
      </View>
    </View>
  );
}
