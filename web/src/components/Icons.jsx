const base = {
  viewBox: '0 0 24 24',
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.8,
  strokeLinecap: 'round',
  strokeLinejoin: 'round',
  width: '1em',
  height: '1em',
  'aria-hidden': true,
}

export const HomeIcon = (p) => (
  <svg {...base} {...p}>
    <path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z" />
  </svg>
)

export const HistoryIcon = (p) => (
  <svg {...base} {...p}>
    <path d="M4 12a8 8 0 1 0 2.6-5.9" />
    <path d="M4 4v4.5h4.5" />
    <path d="M12 8v4.5l3 1.8" />
  </svg>
)

export const WalletIcon = (p) => (
  <svg {...base} {...p}>
    <path d="M3.5 8.5A2.5 2.5 0 0 1 6 6h12a2 2 0 0 1 2 2v9a2.5 2.5 0 0 1-2.5 2.5H6A2.5 2.5 0 0 1 3.5 17z" />
    <path d="M3.5 9.5h17" />
    <circle cx="16.5" cy="14.5" r="1" fill="currentColor" stroke="none" />
  </svg>
)

export const UserIcon = (p) => (
  <svg {...base} {...p}>
    <circle cx="12" cy="8.5" r="3.5" />
    <path d="M5 20c0-3.3 3.1-5.5 7-5.5s7 2.2 7 5.5" />
  </svg>
)

export const SignalIcon = (p) => (
  <svg {...base} {...p}>
    <path d="M4 17h1.8v3H4zM9 13h1.8v7H9zM14 9h1.8v11H14zM19 5h1.8v15H19z" fill="currentColor" stroke="none" />
  </svg>
)

export const WifiIcon = (p) => (
  <svg {...base} {...p}>
    <path d="M4.5 12a11 11 0 0 1 15 0" />
    <path d="M8 15.5a6 6 0 0 1 8 0" />
    <circle cx="12" cy="19" r="1.1" fill="currentColor" stroke="none" />
  </svg>
)

export const BoltIcon = (p) => (
  <svg {...base} {...p}>
    <path d="M13 3 5.5 13.5H11L10 21l7.5-10.5H12z" />
  </svg>
)

export const BillIcon = (p) => (
  <svg {...base} {...p}>
    <path d="M6.5 3.5h11v17l-2.2-1.4-2.3 1.4-2.3-1.4-2.2 1.4z" />
    <path d="M9.5 8.5h5M9.5 12.5h5" />
  </svg>
)

export const GameIcon = (p) => (
  <svg {...base} {...p}>
    <path d="M7.5 8h9a4 4 0 0 1 3.9 4.9l-.8 3.6A2.6 2.6 0 0 1 14.9 18l-1.2-1.6h-3.4L9.1 18a2.6 2.6 0 0 1-4.7-1.5l-.8-3.6A4 4 0 0 1 7.5 8z" />
    <path d="M8.6 11.2v2.4M7.4 12.4h2.4" />
    <circle cx="15.6" cy="11.8" r=".9" fill="currentColor" stroke="none" />
    <circle cx="17.4" cy="13.6" r=".9" fill="currentColor" stroke="none" />
  </svg>
)

export const TicketIcon = (p) => (
  <svg {...base} {...p}>
    <path d="M4 9V6.5h16V9a3 3 0 0 0 0 6v2.5H4V15a3 3 0 0 0 0-6z" />
    <path d="M12 7.5v9" strokeDasharray="2 2.4" />
  </svg>
)

export const InjectIcon = (p) => (
  <svg {...base} {...p}>
    <path d="M12 4.5v8" />
    <path d="m8.6 9.4 3.4 3.4 3.4-3.4" />
    <path d="M4.5 15v2.5A2 2 0 0 0 6.5 19.5h11a2 2 0 0 0 2-2V15" />
  </svg>
)

export const ChevronRightIcon = (p) => (
  <svg {...base} {...p}>
    <path d="m9.5 6 6 6-6 6" />
  </svg>
)

export const CopyIcon = (p) => (
  <svg {...base} {...p}>
    <rect x="9" y="9" width="11" height="11" rx="2.2" />
    <path d="M15 6.5V5.8A1.8 1.8 0 0 0 13.2 4H5.8A1.8 1.8 0 0 0 4 5.8v7.4A1.8 1.8 0 0 0 5.8 15h.7" />
  </svg>
)

export const CheckIcon = (p) => (
  <svg {...base} strokeWidth={2.4} {...p}>
    <path d="m5 12.5 4.5 4.5L19 7" />
  </svg>
)

export const CameraIcon = (p) => (
  <svg {...base} {...p}>
    <path d="M4 8.5h3l1.4-2h7.2l1.4 2h3v9.5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1z" />
    <circle cx="12" cy="13" r="3.2" />
  </svg>
)

export const CloseIcon = (p) => (
  <svg {...base} strokeWidth={2.2} {...p}>
    <path d="M6 6l12 12M18 6 6 18" />
  </svg>
)

export const PlusIcon = (p) => (
  <svg {...base} strokeWidth={2.2} {...p}>
    <path d="M12 5v14M5 12h14" />
  </svg>
)

export const LogoutIcon = (p) => (
  <svg {...base} {...p}>
    <path d="M14 5.5H6.5A1.5 1.5 0 0 0 5 7v10a1.5 1.5 0 0 0 1.5 1.5H14" />
    <path d="M17 8.5 20.5 12 17 15.5" />
    <path d="M10 12h10.5" />
  </svg>
)

export const HelpIcon = (p) => (
  <svg {...base} {...p}>
    <circle cx="12" cy="12" r="8.5" />
    <path d="M9.6 9.4a2.5 2.5 0 1 1 3.6 2.3c-.7.4-1.2 1-1.2 1.8v.3" />
    <circle cx="12" cy="17" r=".9" fill="currentColor" stroke="none" />
  </svg>
)

export const ShieldIcon = (p) => (
  <svg {...base} {...p}>
    <path d="M12 3.5 19 6v6c0 4.2-3 7.2-7 8.5-4-1.3-7-4.3-7-8.5V6z" />
    <path d="m9 12 2.2 2.2L15.2 10" />
  </svg>
)

export const ArrowInIcon = (p) => (
  <svg {...base} {...p}>
    <path d="M12 5v9" />
    <path d="m8.5 10.5 3.5 3.5 3.5-3.5" />
    <path d="M5 19h14" />
  </svg>
)

export const ArrowOutIcon = (p) => (
  <svg {...base} {...p}>
    <path d="M12 19V9.5" />
    <path d="m8.5 13 3.5-3.5 3.5 3.5" />
    <path d="M5 5h14" />
  </svg>
)

export const ClockIcon = (p) => (
  <svg {...base} {...p}>
    <circle cx="12" cy="12" r="8.5" />
    <path d="M12 7.5V12l3 1.8" />
  </svg>
)

export const FileIcon = (p) => (
  <svg {...base} {...p}>
    <path d="M6.5 3.5h7L18 8v12.5H6.5z" />
    <path d="M13 3.5V8h5" />
    <path d="M9.5 12.5h5M9.5 16h5" />
  </svg>
)
