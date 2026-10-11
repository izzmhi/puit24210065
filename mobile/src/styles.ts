import { StyleSheet } from 'react-native';

export const BLUE = '#1f4e8c';

export const styles = StyleSheet.create({
  page: { flex: 1, backgroundColor: '#ffffff' },
  header: {
    backgroundColor: BLUE,
    paddingVertical: 12,
    paddingHorizontal: 16,
    flexDirection: 'row',
    flexWrap: 'wrap',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 8,
  },
  headerTitle: { color: '#ffffff', fontSize: 18, fontWeight: 'bold' },
  nav: { flexDirection: 'row', flexWrap: 'wrap', gap: 16 },
  navLink: { color: '#ffffff', fontSize: 15 },

  body: { padding: 16, alignItems: 'center' },
  content: { width: '100%', maxWidth: 800 },
  form: { width: '100%', maxWidth: 420 },

  heading: { fontSize: 22, fontWeight: 'bold', color: '#222222', marginTop: 8, marginBottom: 12 },
  text: { fontSize: 15, color: '#222222', marginBottom: 8, lineHeight: 21 },
  bold: { fontWeight: 'bold' },
  small: { fontSize: 13, color: '#555555', marginTop: 6 },

  label: { fontSize: 14, color: '#222222', marginTop: 10, marginBottom: 4 },
  input: {
    borderWidth: 1,
    borderColor: '#888888',
    borderRadius: 3,
    paddingVertical: 8,
    paddingHorizontal: 10,
    fontSize: 15,
    backgroundColor: '#ffffff',
    color: '#222222',
  },
  inputError: { borderColor: '#b00020' },
  fieldError: { color: '#b00020', fontSize: 13, marginTop: 3 },

  button: {
    backgroundColor: BLUE,
    paddingVertical: 10,
    borderRadius: 3,
    alignItems: 'center',
    marginTop: 16,
    borderWidth: 1,
    borderColor: BLUE,
  },
  buttonSecondary: { backgroundColor: '#ffffff' },
  buttonText: { color: '#ffffff', fontSize: 15 },
  buttonTextSecondary: { color: BLUE },
  link: { color: BLUE, textDecorationLine: 'underline', fontSize: 14, marginTop: 14 },

  message: { borderWidth: 1, padding: 10, marginBottom: 10, borderRadius: 3 },
  messageText: { fontSize: 14, lineHeight: 20 },

  tableRow: { flexDirection: 'row', borderBottomWidth: 1, borderBottomColor: '#cccccc' },
  tableHead: { backgroundColor: '#eeeeee' },
  cell: { padding: 8, fontSize: 14, color: '#222222' },
});
