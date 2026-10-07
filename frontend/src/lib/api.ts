import axios from 'axios'

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL,
})

type BillingPaymentPayload = {
  card_holder_name: string
  card_number: string
  expiry_date: string
  cvv: string
}

export const billingApi = {
  list: async () => (await api.get('/billing')).data,
  get: async (billingId: string) =>
    (await api.get(`/billing/${billingId}`)).data,
  pay: async (billingId: string, payload: BillingPaymentPayload) =>
    (await api.post(`/payment/${billingId}`, payload)).data,
}
