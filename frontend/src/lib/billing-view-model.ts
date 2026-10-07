export type BillingStatus = 'paid' | 'pending'

export type BillingListItemViewModel = {
  id: string
  planName: string
  customerName: string
  status: BillingStatus
}

export function mapBillingListItem(raw: any): BillingListItemViewModel {
  const status = raw?.status === 'paid' ? 'paid' : 'pending'

  return {
    id: String(raw?.id ?? ''),
    planName: raw?.plan?.name ?? 'Plano',
    customerName: raw?.customer?.name ?? 'Cliente',
    status,
  }
}
