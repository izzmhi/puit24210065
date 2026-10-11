import React from 'react';
import { ActivityIndicator, Pressable, Text, TextInput, TextInputProps, View } from 'react-native';
import { BLUE, styles } from '../styles';

type FieldProps = TextInputProps & { label: string; error?: string | null };

export function Field({ label, error, ...props }: FieldProps) {
  return (
    <View>
      <Text style={styles.label}>{label}</Text>
      <TextInput
        accessibilityLabel={label}
        autoCapitalize="none"
        autoCorrect={false}
        {...props}
        style={[styles.input, error ? styles.inputError : null]}
      />
      {error ? <Text style={styles.fieldError}>{error}</Text> : null}
    </View>
  );
}

type ButtonProps = { title: string; onPress: () => void; loading?: boolean; secondary?: boolean };

export function Button({ title, onPress, loading, secondary }: ButtonProps) {
  return (
    <Pressable
      accessibilityRole="button"
      disabled={loading}
      onPress={onPress}
      style={[styles.button, secondary && styles.buttonSecondary, loading && { opacity: 0.6 }]}
    >
      {loading ? (
        <ActivityIndicator color={secondary ? BLUE : '#ffffff'} />
      ) : (
        <Text style={[styles.buttonText, secondary && styles.buttonTextSecondary]}>{title}</Text>
      )}
    </Pressable>
  );
}

const messageColors = {
  error: { bg: '#fdecea', border: '#b00020', text: '#7a0017' },
  success: { bg: '#e8f5e9', border: '#2e7d32', text: '#1b5e20' },
  warn: { bg: '#fff8e1', border: '#c79100', text: '#5f4300' },
};

export function Message({ kind, children }: { kind: keyof typeof messageColors; children: React.ReactNode }) {
  const c = messageColors[kind];
  return (
    <View
      accessibilityRole={kind === 'error' ? 'alert' : undefined}
      style={[styles.message, { backgroundColor: c.bg, borderColor: c.border }]}
    >
      <Text style={[styles.messageText, { color: c.text }]}>{children}</Text>
    </View>
  );
}

export function Box({ children }: { children: React.ReactNode }) {
  return <View style={styles.box}>{children}</View>;
}

// One "label | value" line inside a box.
export function DetailRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <View style={styles.detailRow}>
      <Text style={styles.detailLabel}>{label}</Text>
      {typeof children === 'string' ? <Text style={styles.detailValue}>{children}</Text> : children}
    </View>
  );
}

export function RoleBadge({ role }: { role: string }) {
  const admin = role === 'admin';
  return (
    <View style={[styles.badge, { backgroundColor: admin ? '#dde8f7' : '#e8eaed' }]}>
      <Text style={[styles.badgeText, { color: admin ? BLUE : '#444444' }]}>{role}</Text>
    </View>
  );
}

// Circle with the user's initials, e.g. "Amina Yusuf" -> "AY".
export function Avatar({ name }: { name: string }) {
  const parts = name.trim().split(/\s+/);
  const initials = ((parts[0]?.[0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
  return (
    <View style={styles.avatar}>
      <Text style={styles.avatarText}>{initials || '?'}</Text>
    </View>
  );
}

export function formatDate(iso: string | null | undefined): string {
  if (!iso) return 'never';
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString();
}
