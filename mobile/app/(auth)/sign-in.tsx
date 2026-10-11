import { Link, useRouter } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';
import { Button, Field, Message } from '../../src/components/ui';
import { useAuth } from '../../src/lib/auth';
import { describeError } from '../../src/lib/errors';
import { normalizeEmail, validateEmail } from '../../src/lib/validation';
import { styles } from '../../src/styles';

export default function SignIn() {
  const router = useRouter();
  const { signIn, flash, clearFlash } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [emailError, setEmailError] = useState<string | null>(null);
  const [passwordError, setPasswordError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    if (busy) return;
    const eErr = validateEmail(email);
    const pErr = password ? null : 'Enter your password.';
    setEmailError(eErr);
    setPasswordError(pErr);
    setError(null);
    if (eErr || pErr) return;

    setBusy(true);
    try {
      await signIn(normalizeEmail(email), password);
      setPassword('');
      router.push('/verify');
    } catch (e) {
      setError(describeError(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <View style={styles.form}>
      <Text style={styles.heading}>Login</Text>

      {flash ? <Message kind="success">{flash}</Message> : null}
      {error ? <Message kind="error">{error}</Message> : null}

      <Field
        label="Email"
        value={email}
        onChangeText={(t) => {
          setEmail(t);
          if (flash) clearFlash();
        }}
        error={emailError}
        keyboardType="email-address"
        autoComplete="email"
      />
      <Field
        label="Password"
        value={password}
        onChangeText={setPassword}
        error={passwordError}
        secureTextEntry
        autoComplete="current-password"
        onSubmitEditing={submit}
      />

      <Button title="Login" onPress={submit} loading={busy} />

      <Text style={styles.small}>Do not have an account?</Text>
      <Link href="/register" style={styles.link}>Register here</Link>
    </View>
  );
}
