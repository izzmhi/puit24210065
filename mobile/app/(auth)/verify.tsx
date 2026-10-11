import { Redirect, useRouter } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';
import { Button, Field, Message } from '../../src/components/ui';
import { useAuth } from '../../src/lib/auth';
import { describeError } from '../../src/lib/errors';
import { validateOtp } from '../../src/lib/validation';
import { styles } from '../../src/styles';

export default function Verify() {
  const router = useRouter();
  const { pending, verifyOtp, resendOtp, cancelPending } = useAuth();
  const [code, setCode] = useState('');
  const [codeError, setCodeError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [info, setInfo] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [resending, setResending] = useState(false);

  // Nothing to verify (page reloaded or cancelled): back to login.
  if (!pending) return <Redirect href="/sign-in" />;

  const submit = async () => {
    if (busy) return;
    const problem = validateOtp(code);
    setCodeError(problem);
    setError(null);
    setInfo(null);
    if (problem) return;

    setBusy(true);
    try {
      await verifyOtp(code);
      router.replace('/dashboard');
    } catch (e) {
      setError(describeError(e));
      setCode('');
    } finally {
      setBusy(false);
    }
  };

  const resend = async () => {
    setResending(true);
    setError(null);
    try {
      await resendOtp();
      setCode('');
      setInfo('A new code was issued. The old code no longer works.');
    } catch (e) {
      setError(describeError(e));
    } finally {
      setResending(false);
    }
  };

  const cancel = () => {
    cancelPending();
    router.replace('/sign-in');
  };

  return (
    <View style={styles.form}>
      <Text style={styles.heading}>Verification code</Text>
      <Text style={styles.text}>
        {pending.delivery === 'email'
          ? `A 6-digit code was sent to ${pending.emailHint}. It is valid for 5 minutes.`
          : 'Enter the 6-digit code to finish logging in.'}
      </Text>

      {pending.delivery === 'development' && pending.devOtp ? (
        <Message kind="warn">
          Development mode (no email server): your code is{' '}
          <Text style={styles.bold}>{pending.devOtp}</Text>
        </Message>
      ) : null}
      {info ? <Message kind="success">{info}</Message> : null}
      {error ? <Message kind="error">{error}</Message> : null}

      <Field
        label="Code"
        value={code}
        onChangeText={(t) => setCode(t.replace(/\D/g, '').slice(0, 6))}
        error={codeError}
        keyboardType="number-pad"
        autoComplete="one-time-code"
        maxLength={6}
        onSubmitEditing={submit}
      />

      <Button title="Verify" onPress={submit} loading={busy} />
      <Button title="Send a new code" onPress={resend} loading={resending} secondary />
      <Button title="Back to login" onPress={cancel} secondary />
    </View>
  );
}
