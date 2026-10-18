import { useCallback, useEffect, useState } from 'react';
import { Pressable, Text, View } from 'react-native';
import { Box, Button, Field, formatDate, Message } from '../../src/components/ui';
import { api, ApiError, RestorePoint } from '../../src/lib/api';
import { confirmAction, saveTextFile } from '../../src/lib/device';
import { describeError } from '../../src/lib/errors';
import { BLUE, styles } from '../../src/styles';

export default function Backup() {
  const [points, setPoints] = useState<RestorePoint[]>([]);
  const [label, setLabel] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [info, setInfo] = useState<string | null>(null);
  const [lastBackup, setLastBackup] = useState<Record<string, number> | null>(null);
  const [busy, setBusy] = useState<'backup' | 'save' | number | null>(null);
  const [forbidden, setForbidden] = useState(false);

  const fail = (e: unknown) => {
    if (e instanceof ApiError && e.status === 403) setForbidden(true);
    setError(
      e instanceof ApiError && e.status === 403
        ? 'Access denied (403). You do not have permission to view this page.'
        : describeError(e),
    );
  };

  const load = useCallback(async () => {
    try {
      setPoints((await api.restorePoints()).restore_points);
    } catch (e) {
      fail(e);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const download = async () => {
    setBusy('backup');
    setError(null);
    setInfo(null);
    try {
      const res = await api.backup();
      await saveTextFile(res.filename, res.content);
      setLastBackup(res.tables);
      setInfo(`Backup ${res.filename} created.`);
    } catch (e) {
      fail(e);
    } finally {
      setBusy(null);
    }
  };

  const save = async () => {
    setBusy('save');
    setError(null);
    setInfo(null);
    try {
      const res = await api.createRestorePoint(label.trim());
      setInfo(`${res.message} (${res.restore_point.label})`);
      setLabel('');
      await load();
    } catch (e) {
      fail(e);
    } finally {
      setBusy(null);
    }
  };

  const apply = async (p: RestorePoint) => {
    const ok = await confirmAction(
      'Apply restore point',
      `Apply "${p.label}"? Every account's role and deleted status goes back to how it was then. Names, emails, passwords and accounts created later are not changed.`,
    );
    if (!ok) return;
    setBusy(p.id);
    setError(null);
    setInfo(null);
    try {
      const res = await api.applyRestorePoint(p.id);
      setInfo(`${res.message} ${res.changed} account(s) changed; ${res.accounts_created_later} newer account(s) left as they are.`);
    } catch (e) {
      fail(e);
    } finally {
      setBusy(null);
    }
  };

  return (
    <View style={{ width: '100%', maxWidth: 640, alignSelf: 'center' }}>
      <Text style={styles.heading}>Backup and restore</Text>

      {error ? <Message kind="error">{error}</Message> : null}
      {info ? <Message kind="success">{info}</Message> : null}

      {forbidden ? null : (
        <>
          <Box>
            <Text style={styles.formHeading}>Full database backup</Text>
            <Text style={styles.formIntro}>
              Downloads every table (users, sessions, codes, logs, restore points) as an SQL file. Passwords are only in
              hashed form; the pepper is not in the database, so it is not in the backup either.
            </Text>
            <Button title="Download backup (.sql)" onPress={download} loading={busy === 'backup'} />
            {lastBackup ? (
              <Text style={styles.small}>
                Rows in the backup:{' '}
                {Object.entries(lastBackup)
                  .map(([t, n]) => `${t} ${n}`)
                  .join(', ')}
                .
              </Text>
            ) : null}
            <Text style={styles.small}>
              To restore it, import the file into the database (phpMyAdmin → Import). This replaces all current data
              with the data in the backup.
            </Text>
          </Box>

          <Box>
            <Text style={styles.formHeading}>Restore points</Text>
            <Text style={styles.formIntro}>
              A restore point saves the configuration only: each account's role and whether it is deleted. Applying it
              puts that configuration back without changing any data.
            </Text>
            <Field
              label="Name (optional)"
              value={label}
              onChangeText={setLabel}
              autoCapitalize="sentences"
              maxLength={100}
              placeholder="e.g. Before changing roles"
            />
            <Button title="Save restore point" onPress={save} loading={busy === 'save'} />

            <View style={styles.divider} />
            {points.length === 0 ? (
              <Text style={styles.small}>No restore points yet.</Text>
            ) : (
              points.map((p) => (
                <View key={p.id} style={[styles.detailRow, { alignItems: 'center' }]}>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.detailValue}>{p.label}</Text>
                    <Text style={styles.small}>
                      {formatDate(p.created_at)} · {p.user_count} account(s){p.created_by ? ` · by ${p.created_by}` : ''}
                    </Text>
                  </View>
                  <Pressable accessibilityRole="button" disabled={busy !== null} onPress={() => void apply(p)}>
                    <Text style={{ color: BLUE, textDecorationLine: 'underline' }}>{busy === p.id ? '...' : 'Apply'}</Text>
                  </Pressable>
                </View>
              ))
            )}
          </Box>
        </>
      )}
    </View>
  );
}
