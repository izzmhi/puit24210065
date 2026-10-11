import { ApiError } from './api';

/** Turns any thrown value into a message that is safe and readable for the user. */
export function describeError(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 429 && error.retryAfter) {
      const minutes = Math.ceil(error.retryAfter / 60);
      return error.retryAfter > 90
        ? `${error.message} You can try again in about ${minutes} minutes.`
        : `${error.message} You can try again in ${error.retryAfter} seconds.`;
    }
    return error.message;
  }
  return 'Something unexpected happened. Please try again.';
}
