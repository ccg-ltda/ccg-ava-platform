/** "+573001112233" for a number-like contact id; anything else (an id of another channel) as it is. */
export const contactNumber = (contactId) => (/^\d{8,}$/.test(contactId) ? `+${contactId}` : contactId);

/** What a conversation is called in lists and headers: the name the contact gave, or their number. */
export const contactLabel = ({ contactName, contactId }) => contactName || contactNumber(contactId);

/** The letter in the round avatar of a contact. */
export const initial = ({ contactName }) => (contactName ? contactName.trim().charAt(0).toUpperCase() : '#');
