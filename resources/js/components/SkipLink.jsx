import React from 'react';

/** Provides a keyboard-first shortcut to the primary page landmark. */
export default function SkipLink({target='main-content',label='Skip to main content'}){
  return <a className="skip-link" href={`#${target}`}>{label}</a>;
}
