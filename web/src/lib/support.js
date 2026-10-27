// Support details are configurable per deployment (VITE_SUPPORT_* at build/dev time).
export const support = {
  email: import.meta.env.VITE_SUPPORT_EMAIL || 'dukungan@sesmartpulsa.id',
  whatsapp: import.meta.env.VITE_SUPPORT_WHATSAPP || '0812-0000-0000',
  hours: import.meta.env.VITE_SUPPORT_HOURS || 'Senin–Sabtu, 08.00–21.00 WITA',
}
