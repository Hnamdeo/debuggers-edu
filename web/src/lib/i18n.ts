import { useSyncExternalStore } from 'react'

// Every on-screen text goes through t('English text'). Hindi is looked up by the English
// text and falls back to English, so a missing translation never breaks a screen.

export type Lang = 'en' | 'hi'
const KEY = 'tts.lang'
let lang: Lang = (() => { try { return localStorage.getItem(KEY) === 'hi' ? 'hi' : 'en' } catch { return 'en' } })()
const listeners = new Set<() => void>()

export function setLang(l: Lang): void {
  lang = l
  try { localStorage.setItem(KEY, l) } catch { /* ignore */ }
  document.documentElement.lang = l
  listeners.forEach(fn => fn())
}

export function useLang(): Lang {
  return useSyncExternalStore(fn => { listeners.add(fn); return () => listeners.delete(fn) }, () => lang)
}

export function t(text: string, vars?: Record<string, string | number>): string {
  let out = (lang === 'hi' && HI[text]) || text
  if (vars) for (const [k, v] of Object.entries(vars)) out = out.replaceAll(`{${k}}`, String(v))
  return out
}

const HI: Record<string, string> = {
  'Sign in': 'साइन इन करें',
  'Sign out': 'साइन आउट',
  'Mobile number or email': 'मोबाइल नंबर या ईमेल',
  'Password': 'पासवर्ड',
  'Show': 'दिखाएँ',
  'Hide': 'छिपाएँ',
  'Forgot password? Ask your school office for a new link.': 'पासवर्ड भूल गए? स्कूल ऑफिस से नया लिंक माँगें।',
  'Good morning': 'सुप्रभात',
  'Good afternoon': 'नमस्कार',
  'Good evening': 'शुभ संध्या',
  'What would you like to do?': 'आप क्या करना चाहेंगे?',
  'Type or speak, e.g. "mark attendance"': 'लिखें या बोलें, जैसे "हाज़िरी लगानी है"',
  'Go': 'चलें',
  'Today': 'आज',
  'Home': 'होम',
  'More': 'और',
  'Back': 'वापस',
  'Save': 'सेव करें',
  'Cancel': 'रद्द करें',
  'Add': 'जोड़ें',
  'Delete': 'हटाएँ',
  'Loading…': 'लोड हो रहा है…',
  'Saved': 'सेव हो गया',
  'Try again': 'फिर से कोशिश करें',
  'Mark attendance': 'हाज़िरी लगाएँ',
  'Attendance': 'हाज़िरी',
  'Give homework': 'होमवर्क दें',
  'Homework': 'होमवर्क',
  'Send a notice': 'सूचना भेजें',
  'Notices': 'सूचनाएँ',
  'Add a student': 'छात्र जोड़ें',
  'Students': 'छात्र',
  'Staff': 'स्टाफ़',
  'School setup': 'स्कूल सेटअप',
  'My children': 'मेरे बच्चे',
  'Present': 'उपस्थित',
  'Absent': 'अनुपस्थित',
  'Late': 'देर से',
  'Leave': 'छुट्टी',
  'Everyone is present. Tap a child who is absent.': 'सभी उपस्थित हैं। जो बच्चा नहीं आया उस पर टैप करें।',
  'Save attendance': 'हाज़िरी सेव करें',
  'Attendance saved': 'हाज़िरी सेव हो गई',
  'Choose a class': 'कक्षा चुनें',
  'Not marked yet': 'अभी नहीं लगी',
  'Marked': 'लग गई',
  'present': 'उपस्थित',
  'absent': 'अनुपस्थित',
  'Students today': 'आज के छात्र',
  'Classes not marked': 'हाज़िरी बाकी',
  'Title': 'शीर्षक',
  'Details': 'विवरण',
  'Due on': 'जमा करने की तारीख',
  'Subject': 'विषय',
  'Class': 'कक्षा',
  'Send': 'भेजें',
  'Search by name or admission no.': 'नाम या प्रवेश संख्या से खोजें',
  'Share on WhatsApp': 'WhatsApp पर भेजें',
  'Copy link': 'लिंक कॉपी करें',
  'Copied': 'कॉपी हो गया',
  'Nothing here yet.': 'अभी यहाँ कुछ नहीं है।',
  'Language': 'भाषा',
  'Welcome!': 'स्वागत है!',
  'Set your password': 'अपना पासवर्ड बनाएँ',
  'At least 8 letters or numbers': 'कम से कम 8 अक्षर या अंक',
  'Start': 'शुरू करें',
  'Today’s attendance': 'आज की हाज़िरी',
  'No homework this week': 'इस हफ़्ते कोई होमवर्क नहीं',
  'homework this week': 'इस हफ़्ते होमवर्क',
  'This month': 'इस महीने',
  'Sorry, I didn\'t get that. Please pick one of the buttons below.': 'माफ़ कीजिए, मैं समझ नहीं पाया। नीचे दिए बटन में से चुनें।',
}
