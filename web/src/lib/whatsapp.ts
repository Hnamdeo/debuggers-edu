// Share an invite link on WhatsApp, the way most Indian schools already talk to parents.
export function whatsappLink(phone: string | null | undefined, text: string): string {
  const to = phone ? `91${phone.replace(/\D/g, '').slice(-10)}` : ''
  return `https://wa.me/${to}?text=${encodeURIComponent(text)}`
}
