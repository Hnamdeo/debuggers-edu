import { useState } from 'react'
import { t } from '../lib/i18n'
import { whatsappLink } from '../lib/whatsapp'

/** The one-time sign-up link, with a WhatsApp button so the office can send it in one tap. */
export function ShareLink({ link, name, phone, schoolName }: { link: string; name: string; phone?: string | null; schoolName: string }) {
  const [copied, setCopied] = useState(false)
  const text = `Namaste ${name}! ${schoolName} is now on Debuggers Edu. Tap this link to set your password and start: ${link}`
  return (
    <div className="share">
      <p className="small">Send this link to <b>{name}</b>. It works once and lasts 14 days.</p>
      <div className="share-buttons">
        <a className="btn btn-wa" href={whatsappLink(phone, text)} target="_blank" rel="noopener noreferrer">🟢 {t('Share on WhatsApp')}</a>
        <button className="btn btn-soft" onClick={() => { void navigator.clipboard?.writeText(link).then(() => setCopied(true)) }}>{copied ? t('Copied') : t('Copy link')}</button>
      </div>
    </div>
  )
}
