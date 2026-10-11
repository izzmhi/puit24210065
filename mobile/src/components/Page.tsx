import React from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Link } from 'expo-router';
import { useAuth } from '../lib/auth';
import { styles } from '../styles';

// Header with the app name (and the menu when logged in) above every screen.
export function Page({ children }: { children: React.ReactNode }) {
  const { user, signOut } = useAuth();

  return (
    <SafeAreaView style={styles.page}>
      <View style={styles.header}>
        <Text style={styles.headerTitle}>Secure Login System</Text>
        {user ? (
          <View style={styles.nav}>
            <Link href="/dashboard" style={styles.navLink}>Dashboard</Link>
            <Link href="/profile" style={styles.navLink}>Profile</Link>
            {user.role === 'admin' ? <Link href="/admin" style={styles.navLink}>Admin</Link> : null}
            <Pressable accessibilityRole="button" onPress={() => void signOut()}>
              <Text style={styles.navLink}>Logout</Text>
            </Pressable>
          </View>
        ) : null}
      </View>
      <ScrollView contentContainerStyle={styles.body} keyboardShouldPersistTaps="handled">
        <View style={styles.content}>{children}</View>
      </ScrollView>
    </SafeAreaView>
  );
}
