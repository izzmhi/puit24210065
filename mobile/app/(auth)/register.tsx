import { Link, useRouter } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';
import { Button, Field, Message } from '../../src/components/ui';
import { ApiError } from '../../src/lib/api';
import { useAuth } from '../../src/lib/auth';
import { describeError } from '../../src/lib/errors';
import {
  normalizeEmail,
  PASSWORD_MIN,
  validateEmail,
  validateName,
  validatePassword,
} from '../../src/lib/validation';
import { styles } from '../../src/styles';

type Errors = { name?: string | null; email?: string | null; password?: string | null; confirm?: string | null };

export default function Register() {
  const router = useRouter();
  const { register } = useAuth();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [errors, setErrors] = useState<Errors>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    if (busy) return;
    const found: Errors = {
      name: validateName(name),
      email: validateEmail(email),
      password: validatePassword(password, email),
      confirm: confirm === password ? null : 'The two passwords do not match.',
    };
    setErrors(found);
    setError(null);
    if (Object.values(found).some(Boolean)) return;

    setBusy(true);
    try {
      await register(name.trim(), normalizeEmail(email), password);
      router.replace('/sign-in');
    } catch (e) {
      if (e instanceof ApiError && Object.keys(e.fieldErrors).length > 0) {
        // messages from the server go under the matching field
        setErrors({ name: e.fieldErrors.name, email: e.fieldErrors.email, password: e.fieldErrors.password });
      } else {
        setError(describeError(e));
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <View style={styles.form}>
      <Text style={styles.heading}>Register</Text>

      {error ? <Message kind="error">{error}</Message> : null}

      <Field label="Full name" value={name} onChangeText={setName} error={errors.name} autoCapitalize="words" />
      <Field
        label="Email"
        value={email}
        onChangeText={setEmail}
        error={errors.email}
        keyboardType="email-address"
        autoComplete="email"
      />
      <Field
        label="Password"
        value={password}
        onChangeText={setPassword}
        error={errors.password}
        secureTextEntry
        autoComplete="new-password"
      />
      <Text style={styles.small}>At least {PASSWORD_MIN} characters, with a letter and a number.</Text>
      <Field
        label="Confirm password"
        value={confirm}
        onChangeText={setConfirm}
        error={errors.confirm}
        secureTextEntry
        autoComplete="new-password"
        onSubmitEditing={submit}
      />

      <Button title="Register" onPress={submit} loading={busy} />

      <Text style={styles.small}>Already have an account?</Text>
      <Link href="/sign-in" style={styles.link}>Back to login</Link>
    </View>
  );
}
