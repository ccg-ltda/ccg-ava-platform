/**
 * Whole-row navigation for lists: a click anywhere on the row opens its detail, except on what already does something (links, buttons, fields,
 * menus) and when the person is selecting text to copy it. The row's own link stays the keyboard target, so focus and screen readers work as before.
 */
const INTERACTIVE = 'a,button,input,select,textarea,label,summary,[role="button"],[role="link"],[role="menuitem"],[role="checkbox"],[contenteditable="true"]';

/** Whether the element that received the click (or one of its ancestors up to the row) handles clicks by itself. */
export const isInteractiveTarget = (target) => Boolean(target?.closest?.(INTERACTIVE));

/** Whether this click on a row should open its detail. `selection` is the text currently selected in the page. */
export const shouldOpenRow = (event, selection = '') => event.button === 0 && !event.defaultPrevented && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey && selection === '' && !isInteractiveTarget(event.target);
