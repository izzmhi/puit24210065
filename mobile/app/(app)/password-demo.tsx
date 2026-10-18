import { useState } from 'react';
import { Text, View } from 'react-native';
import { Box, Button, Field, Message } from '../../src/components/ui';
import { api, ApiError, PasswordDemoResponse } from '../../src/lib/api';
import { useAuth } from '../../src/lib/auth';
import { describeError } from '../../src/lib/errors';
import { styles } from '../../src/styles';

function Step({ n, title, children }: { n: number; title: string; children: React.ReactNode }) {
  return (
    <View style={{ marginBottom: 14 }}>
      <Text style={[styles.text, styles.bold, { marginBottom: 4 }]}>
        Step {n}: {title}
      </Text>
      {children}
    </View>
  );
}

function Value({ children }: { children: string }) {
  return (
    <Text selectable style={[styles.mono, { backgroundColor: '#f3f5f8', padding: 6, borderRadius: 3 }]}>
      {children}
    </Text>
  );
}

const yesNo = (ok: boolean) => (ok ? 'accepted ✓' : 'rejected ✗');

export default function PasswordDemo() {
  const { user } = useAuth();
  const [password, setPassword] = useState('');
  const [data, setData] = useState<PasswordDemoResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const run = async () => {
    if (!password) {
      setError('Type a sample password first.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      setData(await api.passwordDemo(password));
    } catch (e) {
      setData(null);
      setError(
        e instanceof ApiError && e.status === 403
          ? 'Access denied (403). You do not have permission to view this page.'
          : describeError(e),
      );
    } finally {
      setBusy(false);
    }
  };

  // The server refuses this for students anyway (403); this just says so before any request.
  if (user?.role !== 'admin') {
    return (
      <View style={{ width: '100%', maxWidth: 680, alignSelf: 'center' }}>
        <Text style={styles.heading}>Password demo: salt and pepper</Text>
        <Message kind="error">Access denied (403). Only administrators can use this page.</Message>
      </View>
    );
  }

  return (
    <View style={{ width: '100%', maxWidth: 680, alignSelf: 'center' }}>
      <Text style={styles.heading}>Password demo: salt and pepper</Text>

      <Box>
        <Text style={styles.formIntro}>
          Type any sample password to see how the server stores passwords. Nothing typed here is saved, and the secret
          pepper itself is never shown.
        </Text>
        <Field label="Sample password" value={password} onChangeText={setPassword} onSubmitEditing={run} maxLength={128} />
        <Button title="Show the steps" onPress={run} loading={busy} />
      </Box>

      {error ? <Message kind="error">{error}</Message> : null}

      {data ? (
        <Box>
          <Step n={1} title="Add the pepper">
            <Text style={styles.small}>
              The password is combined with the pepper, a secret kept only on the server (never in the database), using
              HMAC-SHA256. Result (shortened):
            </Text>
            <Value>{data.peppered_preview}</Value>
          </Step>

          <Step n={2} title="Create a random salt">
            <Text style={styles.small}>password_hash() makes a new random salt every time:</Text>
            <Value>{data.salt_1}</Value>
          </Step>

          <Step n={3} title={`Hash with ${data.algorithm}`}>
            <Text style={styles.small}>
              This is what would be saved in the database. The algorithm, its settings and the salt are part of it:
            </Text>
            <Value>{data.hash_1}</Value>
          </Step>

          <Step n={4} title="Same password again">
            <Text style={styles.small}>A new salt gives a completely different hash for the same password:</Text>
            <Value>{data.salt_2}</Value>
            <View style={{ height: 6 }} />
            <Value>{data.hash_2}</Value>
            <Text style={[styles.small, styles.bold]}>
              Same hash twice? {data.same_hash ? 'yes' : 'no'}. Two users with the same password still get different hashes.
            </Text>
          </Step>

          <Step n={5} title="Checking a login (password_verify)">
            <Text style={styles.text}>Correct password: {yesNo(data.verify_correct_password)}</Text>
            <Text style={styles.text}>Wrong password: {yesNo(data.verify_wrong_password)}</Text>
            <Text style={styles.text}>Correct password but without the pepper: {yesNo(data.verify_without_pepper)}</Text>
            <Text style={styles.small}>
              So even someone who steals the database cannot check password guesses without the pepper from the server.
            </Text>
          </Step>
        </Box>
      ) : null}
    </View>
  );
}
