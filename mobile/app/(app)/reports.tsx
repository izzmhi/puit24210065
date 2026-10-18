import { useCallback, useEffect, useState } from 'react';
import { Platform, ScrollView, Text, View } from 'react-native';
import { Box, Button, DetailRow, formatDate, Message } from '../../src/components/ui';
import { api, ApiError, ReportsResponse } from '../../src/lib/api';
import { describeError } from '../../src/lib/errors';
import { BLUE, styles } from '../../src/styles';

const eventNames: Record<string, string> = {
  register: 'Registrations',
  password_ok: 'Correct passwords (code sent)',
  login_failed: 'Failed logins',
  code_failed: 'Wrong verification codes',
  login_success: 'Successful logins',
  logout: 'Logouts',
  user_deleted: 'Accounts deleted',
  user_restored: 'Accounts restored',
  backup_downloaded: 'Backups downloaded',
  restore_point_created: 'Restore points saved',
  restore_point_applied: 'Restore points applied',
};

const widths = { time: 170, event: 190, email: 210, detail: 230, ip: 130 };

export default function Reports() {
  const [data, setData] = useState<ReportsResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      setData(await api.reports());
    } catch (e) {
      setData(null);
      setError(
        e instanceof ApiError && e.status === 403
          ? 'Access denied (403). You do not have permission to view this page.'
          : describeError(e),
      );
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const maxLogins = data ? Math.max(1, ...data.last_7_days.logins_per_day.map((d) => d.logins)) : 1;

  return (
    <View>
      <Text style={styles.heading}>Reports</Text>

      {loading && !data ? <Text style={styles.text}>Loading reports...</Text> : null}
      {error ? <Message kind="error">{error}</Message> : null}

      {data ? (
        <>
          <Box>
            <Text style={styles.formHeading}>Accounts</Text>
            <DetailRow label="Total accounts">{String(data.users.total)}</DetailRow>
            <DetailRow label="Admins">{String(data.users.admins)}</DetailRow>
            <DetailRow label="Students">{String(data.users.students)}</DetailRow>
            <DetailRow label="Deleted">{String(data.users.deleted)}</DetailRow>
            <DetailRow label="Active sessions">{String(data.users.active_sessions)}</DetailRow>
            <DetailRow label="Restore points">{String(data.restore_points)}</DetailRow>
            <DetailRow label="Last backup">{data.last_backup ? formatDate(data.last_backup) : 'never'}</DetailRow>
          </Box>

          <Box>
            <Text style={styles.formHeading}>Successful logins, last 7 days</Text>
            {data.last_7_days.logins_per_day.map((d) => (
              <View key={d.date} style={[styles.detailRow, { alignItems: 'center' }]}>
                <Text style={styles.detailLabel}>{d.date}</Text>
                <View style={{ flex: 1, flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                  <View style={{ height: 12, width: `${(d.logins / maxLogins) * 80}%`, backgroundColor: BLUE, minWidth: d.logins ? 4 : 0 }} />
                  <Text style={styles.detailValue}>{d.logins}</Text>
                </View>
              </View>
            ))}
          </Box>

          <Box>
            <Text style={styles.formHeading}>Security events, last 7 days</Text>
            {Object.keys(data.last_7_days.events).length === 0 ? (
              <Text style={styles.small}>No events yet.</Text>
            ) : (
              Object.entries(data.last_7_days.events).map(([event, count]) => (
                <DetailRow key={event} label={eventNames[event] ?? event}>
                  {String(count)}
                </DetailRow>
              ))
            )}
          </Box>

          <Box>
            <Text style={styles.formHeading}>Recent activity</Text>
            <ScrollView horizontal>
              <View>
                <View style={[styles.tableRow, styles.tableHead]}>
                  <Text style={[styles.cell, styles.bold, { width: widths.time }]}>Time</Text>
                  <Text style={[styles.cell, styles.bold, { width: widths.event }]}>Event</Text>
                  <Text style={[styles.cell, styles.bold, { width: widths.email }]}>Account</Text>
                  <Text style={[styles.cell, styles.bold, { width: widths.detail }]}>Detail</Text>
                  <Text style={[styles.cell, styles.bold, { width: widths.ip }]}>IP address</Text>
                </View>
                {data.recent_events.map((e, i) => (
                  <View key={`${e.time}-${i}`} style={styles.tableRow}>
                    <Text style={[styles.cell, { width: widths.time }]}>{formatDate(e.time)}</Text>
                    <Text style={[styles.cell, { width: widths.event }, e.event.endsWith('failed') && { color: '#b00020' }]}>
                      {eventNames[e.event] ?? e.event}
                    </Text>
                    <Text style={[styles.cell, { width: widths.email }]}>{e.email ?? '-'}</Text>
                    <Text style={[styles.cell, { width: widths.detail }]}>{e.detail ?? ''}</Text>
                    <Text style={[styles.cell, { width: widths.ip }]}>{e.ip ?? ''}</Text>
                  </View>
                ))}
              </View>
            </ScrollView>
            <Text style={styles.small}>Passwords and verification codes are never written to this log.</Text>
          </Box>
        </>
      ) : null}

      <View style={{ flexDirection: 'row', gap: 12, maxWidth: 420 }}>
        <View style={{ flex: 1 }}>
          <Button title="Refresh" onPress={load} loading={loading} secondary />
        </View>
        {Platform.OS === 'web' && data ? (
          <View style={{ flex: 1 }}>
            <Button title="Print / save as PDF" onPress={() => window.print()} secondary />
          </View>
        ) : null}
      </View>
    </View>
  );
}
